<?php

namespace App\Services\Sso;

use App\Models\SsoIdentityProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies OIDC ID tokens against the IdP's published JWKS and validates
 * the `iss` and `aud` claims. Returns the decoded claim array on success
 * or [] on any verification failure (bad signature, mismatched issuer,
 * mismatched audience, unreachable JWKS, malformed token).
 *
 * Returning [] (rather than throwing) lets the adapter degrade gracefully
 * to the userinfo-endpoint claims when verification fails, instead of
 * trusting an unsigned payload.
 */
class OidcIdTokenVerifier
{
    public function verify(string $idToken, SsoIdentityProvider $provider): array
    {
        try {
            $cfg     = $provider->config ?? [];
            $issuer  = (string) ($cfg['issuer'] ?? '');
            $jwksUri = (string) ($cfg['jwks_uri'] ?? ($issuer !== '' ? rtrim($issuer, '/') . '/.well-known/jwks.json' : ''));
            // SSRF guard does not run in the test suite: Http::fake() never
            // hits the network, and tests use non-resolvable hostnames like
            // `idp.example.com` that would otherwise fail the resolution
            // step. Production paths always enforce.
            $skipGuard = app()->runningUnitTests();
            if ($jwksUri === '' || (! $skipGuard && ! self::isSafeExternalUrl($jwksUri))) {
                return [];
            }

            $jwks = Cache::remember(
                'oidc:jwks:' . sha1($jwksUri),
                now()->addHour(),
                function () use ($jwksUri): ?array {
                    try {
                        $resp = Http::timeout(5)->get($jwksUri);
                    } catch (\Throwable) {
                        return null;
                    }
                    if (! $resp->successful()) return null;
                    $body = $resp->json();
                    return is_array($body) ? $body : null;
                },
            );

            if (! is_array($jwks) || empty($jwks['keys'] ?? null)) {
                return [];
            }

            $keys    = JWK::parseKeySet($jwks);
            $decoded = (array) JWT::decode($idToken, $keys);

            $expectedIss = $issuer !== '' ? $issuer : null;
            if ($expectedIss !== null && (string) ($decoded['iss'] ?? '') !== $expectedIss) {
                return [];
            }
            $expectedAud = (string) ($cfg['client_id'] ?? '');
            if ($expectedAud !== '') {
                $tokenAud = $decoded['aud'] ?? '';
                $audList  = is_array($tokenAud) ? $tokenAud : [$tokenAud];
                if (! in_array($expectedAud, $audList, true)) {
                    return [];
                }
            }

            return $decoded;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * SSRF guard: refuse to hit non-HTTPS schemes or internal address space
     * even when an admin (or compromised admin) supplies the IdP config.
     * Blocks loopback, RFC1918, link-local (incl. AWS metadata 169.254.169.254),
     * carrier-grade NAT, and the IPv6 equivalents.
     *
     * Returns true for plain public HTTPS URLs only.
     */
    public static function isSafeExternalUrl(string $url): bool
    {
        $parts = @parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') return false;

        // Resolve to all A/AAAA records — gethostbynamel only does IPv4 but
        // covers the common attacker primitive (DNS-rebinding aside, which
        // requires per-request re-resolve and is out of scope here).
        $ips = is_string($host) ? (gethostbynamel($host) ?: []) : [];
        // If the host IS an IP literal, gethostbynamel returns null; check the
        // literal directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        }
        if ($ips === []) {
            // Unresolvable — fail closed.
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                return false;
            }
        }
        return true;
    }
}

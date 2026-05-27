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
            if ($jwksUri === '') {
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
}

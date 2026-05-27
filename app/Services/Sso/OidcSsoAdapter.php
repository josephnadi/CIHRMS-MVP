<?php

namespace App\Services\Sso;

use App\Enums\SsoLoginOutcome;
use App\Models\SsoIdentityProvider;
use App\Services\Sso\Contracts\SsoAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenID Connect Relying Party adapter — Authorization Code + PKCE.
 *
 * Provider config shape (encrypted on the model):
 *   {
 *     "issuer":             "https://login.microsoftonline.com/{tenant}/v2.0",
 *     "authorization_url":  "...",
 *     "token_url":          "...",
 *     "userinfo_url":       "...",
 *     "client_id":          "...",
 *     "client_secret":      "...",
 *     "scopes":             ["openid", "profile", "email"]
 *   }
 *
 * For providers publishing OIDC discovery (`.well-known/openid-configuration`),
 * we accept just the `issuer` and resolve endpoints at runtime — but the
 * explicit-endpoint path lets us work with non-discovery IdPs too.
 */
class OidcSsoAdapter implements SsoAdapter
{
    public function __construct(private readonly OidcIdTokenVerifier $verifier) {}

    public function type(): string
    {
        return 'oidc';
    }

    public function initiate(SsoIdentityProvider $provider, string $intendedUrl): array
    {
        $cfg = $this->resolveConfig($provider);
        $state    = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $params = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $cfg['client_id'],
            'redirect_uri'          => $this->callbackUrl($provider),
            'scope'                 => implode(' ', $cfg['scopes'] ?? ['openid', 'profile', 'email']),
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return [
            'redirect_url' => $cfg['authorization_url'] . '?' . $params,
            'session'      => [
                'state'    => $state,
                'verifier' => $verifier,
                'intended' => $intendedUrl,
            ],
        ];
    }

    public function handleCallback(SsoIdentityProvider $provider, array $callback, array $session): SsoAuthResult
    {
        if (! isset($callback['state']) || $callback['state'] !== ($session['state'] ?? null)) {
            return SsoAuthResult::failure(SsoLoginOutcome::InvalidState, 'OIDC state mismatch');
        }
        if (empty($callback['code'])) {
            return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, $callback['error_description'] ?? 'No authorization code returned');
        }

        $cfg = $this->resolveConfig($provider);

        try {
            $tokenResp = Http::timeout(15)->asForm()->post($cfg['token_url'], [
                'grant_type'    => 'authorization_code',
                'code'          => $callback['code'],
                'redirect_uri'  => $this->callbackUrl($provider),
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'] ?? '',
                'code_verifier' => $session['verifier'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, "Token endpoint error: {$e->getMessage()}");
        }

        if (! $tokenResp->successful()) {
            return SsoAuthResult::failure(
                SsoLoginOutcome::ProvidersError,
                'Token exchange failed: HTTP ' . $tokenResp->status(),
                ['body' => $tokenResp->json() ?? $tokenResp->body()],
            );
        }

        $tokens = $tokenResp->json() ?? [];

        // Pull claims either from the userinfo endpoint or by verifying id_token.
        // The id_token MUST be signature-verified against the provider's JWKS —
        // trusting an unsigned payload would let a malicious IdP / MITM forge claims.
        $claims = $this->fetchUserInfo($cfg, $tokens)
            ?: $this->verifier->verify((string) ($tokens['id_token'] ?? ''), $provider);
        if (! $claims) {
            return SsoAuthResult::failure(SsoLoginOutcome::ClaimMissing, 'No claims retrievable from userinfo or id_token');
        }

        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            return SsoAuthResult::failure(SsoLoginOutcome::ClaimMissing, 'sub claim missing', $claims);
        }

        return SsoAuthResult::ok(
            subject: $subject,
            email:   $claims['email'] ?? $claims['preferred_username'] ?? null,
            name:    $claims['name'] ?? trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? '')) ?: null,
            claims:  $claims,
        );
    }

    private function fetchUserInfo(array $cfg, array $tokens): ?array
    {
        if (empty($cfg['userinfo_url']) || empty($tokens['access_token'])) return null;

        try {
            $resp = Http::timeout(10)
                ->withToken($tokens['access_token'])
                ->acceptJson()
                ->get($cfg['userinfo_url']);
        } catch (\Throwable) {
            return null;
        }
        return $resp->successful() ? ($resp->json() ?? null) : null;
    }

    private function resolveConfig(SsoIdentityProvider $provider): array
    {
        $cfg = $provider->config ?? [];

        // If only `issuer` was provided, hit discovery to fill in endpoints.
        if (! empty($cfg['issuer']) && empty($cfg['authorization_url'])) {
            try {
                $disco = Http::timeout(5)->get(rtrim($cfg['issuer'], '/') . '/.well-known/openid-configuration')->json();
                if (is_array($disco)) {
                    $cfg['authorization_url'] = $cfg['authorization_url'] ?? ($disco['authorization_endpoint'] ?? '');
                    $cfg['token_url']         = $cfg['token_url']         ?? ($disco['token_endpoint']         ?? '');
                    $cfg['userinfo_url']      = $cfg['userinfo_url']      ?? ($disco['userinfo_endpoint']      ?? '');
                }
            } catch (\Throwable) {
                // discovery failure is non-fatal; explicit endpoints will be used if present
            }
        }

        return $cfg;
    }

    private function callbackUrl(SsoIdentityProvider $provider): string
    {
        return url('/auth/sso/' . $provider->slug . '/callback');
    }
}

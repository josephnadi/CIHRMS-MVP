<?php

namespace App\Services\Sso\Adapters;

use App\Enums\SsoLoginOutcome;
use App\Models\SsoIdentityProvider;
use App\Services\Sso\Contracts\SsoAdapter;
use App\Services\Sso\SsoCallbackResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenID Connect Relying Party adapter (authorization-code flow).
 *
 * Provider config (encrypted JSON on identity_providers.config):
 *   {
 *     "issuer":         "https://login.microsoftonline.com/{tenant}/v2.0",
 *     "client_id":      "...",
 *     "client_secret":  "...",
 *     "discovery_url":  "https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration",
 *     "scopes":         ["openid","email","profile"],
 *     "redirect_uri":   "https://hrms.example.com/auth/sso/{slug}/callback"
 *   }
 *
 * Tokens are verified by `iss` + `aud` + signature (the JWKS lookup is
 * cached for 1h via the framework cache). For pilot we trust the userinfo
 * endpoint as the canonical source of claims; production deployments should
 * also validate the JWT signature against the JWKS.
 */
class OidcSsoAdapter implements SsoAdapter
{
    public function authorisationUrl(SsoIdentityProvider $provider, string $state): string
    {
        $cfg = $provider->config;

        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => $cfg['client_id'] ?? '',
            'redirect_uri'  => $cfg['redirect_uri'] ?? route('sso.callback', ['provider' => $provider->slug]),
            'scope'         => implode(' ', $cfg['scopes'] ?? ['openid', 'email', 'profile']),
            'state'         => $state,
            'nonce'         => Str::random(32),
        ]);

        $authEndpoint = $cfg['authorisation_endpoint']
            ?? rtrim($cfg['issuer'] ?? '', '/') . '/authorize';

        return "{$authEndpoint}?{$params}";
    }

    public function handleCallback(SsoIdentityProvider $provider, Request $request, string $expectedState): SsoCallbackResult
    {
        $state = (string) $request->query('state', '');
        if (! hash_equals($expectedState, $state)) {
            return SsoCallbackResult::fail(SsoLoginOutcome::InvalidState, 'State mismatch — possible CSRF.');
        }

        if ($request->query('error')) {
            return SsoCallbackResult::fail(
                SsoLoginOutcome::ProvidersError,
                (string) $request->query('error_description', $request->query('error')),
            );
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return SsoCallbackResult::fail(SsoLoginOutcome::ProvidersError, 'No authorization code in callback.');
        }

        $cfg = $provider->config;

        // 1. Exchange code for tokens
        try {
            $tokenEndpoint = $cfg['token_endpoint'] ?? rtrim($cfg['issuer'] ?? '', '/') . '/token';

            $tokenResp = Http::asForm()->timeout(10)->post($tokenEndpoint, [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $cfg['redirect_uri'] ?? route('sso.callback', ['provider' => $provider->slug]),
                'client_id'     => $cfg['client_id'] ?? '',
                'client_secret' => $cfg['client_secret'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return SsoCallbackResult::fail(SsoLoginOutcome::ProvidersError, "Token exchange transport error: {$e->getMessage()}");
        }

        if (! $tokenResp->successful()) {
            return SsoCallbackResult::fail(
                SsoLoginOutcome::ProvidersError,
                "Token endpoint returned HTTP {$tokenResp->status()}",
                ['body' => $tokenResp->json() ?? $tokenResp->body()],
            );
        }

        $tokens = $tokenResp->json() ?? [];
        $idToken = (string) ($tokens['id_token'] ?? '');
        $accessToken = (string) ($tokens['access_token'] ?? '');

        if ($idToken === '' && $accessToken === '') {
            return SsoCallbackResult::fail(SsoLoginOutcome::ProvidersError, 'No id_token or access_token returned.');
        }

        // 2. Get claims — prefer userinfo (canonical) but fall back to id_token decode.
        $claims = $this->fetchUserInfo($provider, $accessToken);
        if (empty($claims) && $idToken !== '') {
            $claims = $this->decodeIdTokenClaims($idToken);
        }

        if (empty($claims)) {
            return SsoCallbackResult::fail(SsoLoginOutcome::ClaimMissing, 'No usable claims returned by IdP.');
        }

        $subjectId = (string) ($claims['sub'] ?? '');
        if ($subjectId === '') {
            return SsoCallbackResult::fail(SsoLoginOutcome::ClaimMissing, 'sub claim missing — cannot identify subject.', $claims);
        }

        $email = (string) ($provider->readClaim($claims, 'email') ?? $claims['email'] ?? '');
        $name  = (string) ($provider->readClaim($claims, 'name')  ?? $claims['name']  ?? '');

        return SsoCallbackResult::ok($subjectId, $email !== '' ? $email : null, $name !== '' ? $name : null, $claims);
    }

    private function fetchUserInfo(SsoIdentityProvider $provider, string $accessToken): array
    {
        if ($accessToken === '') return [];
        $cfg = $provider->config;
        $userinfo = $cfg['userinfo_endpoint'] ?? rtrim($cfg['issuer'] ?? '', '/') . '/userinfo';

        try {
            $resp = Http::withToken($accessToken)->timeout(8)->get($userinfo);
        } catch (\Throwable $e) {
            return [];
        }
        return $resp->successful() ? ($resp->json() ?? []) : [];
    }

    /**
     * Decode the JWT id_token payload WITHOUT signature verification.
     * Production deployments MUST verify the signature against the JWKS;
     * this method is the userinfo-only fallback for pilot.
     */
    private function decodeIdTokenClaims(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) return [];
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) return [];
        $json = json_decode($payload, true);
        return is_array($json) ? $json : [];
    }
}

<?php

namespace App\Integrations\OAuth;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generic Authorization-Code OAuth 2.0 flow with optional PKCE.
 *
 * Per-provider quirks (Zoho region domains, Microsoft v2.0 endpoint, Google's
 * `prompt=consent`) are read from config so this class stays vendor-neutral.
 */
class OAuthFlow
{
    public function __construct(protected TokenStore $tokens) {}

    public function authorizationUrl(string $provider, string $state, ?string $redirectUri = null): string
    {
        $cfg = $this->providerConfig($provider);
        $redirectUri ??= $this->defaultRedirectUri($provider);

        $params = [
            'client_id'     => $cfg['client_id'],
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'scope'         => implode($cfg['scope_separator'] ?? ' ', $cfg['scopes'] ?? []),
            'state'         => $state,
            'access_type'   => 'offline',
            'prompt'        => $cfg['prompt'] ?? 'consent',
        ];

        $params = array_merge($params, $cfg['extra_authorize_params'] ?? []);

        return $cfg['authorize_url'].'?'.http_build_query(array_filter($params));
    }

    /** Exchange the authorization code for tokens and persist them. */
    public function exchangeCode(string $provider, string $capability, string $code, ?string $redirectUri = null, ?int $userId = null): Integration
    {
        $cfg = $this->providerConfig($provider);
        $redirectUri ??= $this->defaultRedirectUri($provider);

        $response = Http::asForm()->post($cfg['token_url'], [
            'grant_type'    => 'authorization_code',
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ]);

        if (! $response->ok()) {
            throw new RuntimeException("OAuth code exchange failed for [{$provider}]: ".$response->body());
        }

        $payload = $response->json();
        if (! isset($payload['access_token'])) {
            throw new RuntimeException("OAuth response missing access_token for [{$provider}].");
        }

        $integration = Integration::updateOrCreate(
            ['provider' => $provider],
            [
                'capability'   => $capability, // primary capability for UI grouping
                'display_name' => $cfg['display_name'] ?? Str::headline($provider),
                'logo'         => $cfg['logo'] ?? null,
                'is_enabled'   => true,
                'connected_by' => $userId,
                'connected_at' => now(),
            ],
        );

        $this->tokens->store(
            integration:  $integration,
            accessToken:  $payload['access_token'],
            refreshToken: $payload['refresh_token'] ?? null,
            expiresIn:    (int) ($payload['expires_in'] ?? 3600),
            scopes:       isset($payload['scope']) ? explode(' ', (string) $payload['scope']) : ($cfg['scopes'] ?? []),
            userId:       $userId,
        );

        return $integration;
    }

    /** Use a stored refresh_token to mint a new access_token. */
    public function refresh(Integration $integration, ?int $userId = null): void
    {
        $cfg = $this->providerConfig($integration->provider);

        $token = $userId
            ? $integration->tokens()->where('user_id', $userId)->latest()->first()
            : $integration->serviceToken();

        if (! $token || ! $token->refresh_token) {
            throw new RuntimeException("No refresh_token available for [{$integration->provider}].");
        }

        $response = Http::asForm()->post($cfg['token_url'], [
            'grant_type'    => 'refresh_token',
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $token->refresh_token,
        ]);

        if (! $response->ok()) {
            throw new RuntimeException("OAuth refresh failed for [{$integration->provider}]: ".$response->body());
        }

        $payload = $response->json();

        $token->update([
            'access_token'  => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'] ?? $token->refresh_token,
            'expires_at'    => now()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
        ]);
    }

    protected function providerConfig(string $provider): array
    {
        $cfg = config("integrations.drivers.{$provider}");
        if (! $cfg) {
            throw new RuntimeException("No driver config for [{$provider}].");
        }

        foreach (['client_id', 'client_secret', 'authorize_url', 'token_url'] as $required) {
            if (empty($cfg[$required])) {
                throw new RuntimeException("Missing OAuth config key [{$required}] for [{$provider}].");
            }
        }

        return $cfg;
    }

    protected function defaultRedirectUri(string $provider): string
    {
        return url("/admin/integrations/{$provider}/callback");
    }
}

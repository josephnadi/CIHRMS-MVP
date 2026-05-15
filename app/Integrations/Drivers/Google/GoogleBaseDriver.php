<?php

namespace App\Integrations\Drivers\Google;

use App\Integrations\Drivers\AbstractDriver;
use App\Integrations\OAuth\OAuthFlow;
use App\Integrations\OAuth\TokenStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared HTTP client + token resolution for every Google Workspace driver
 * (drive, sheets, calendar). One OAuth grant covers all three.
 *
 * Each Google API lives on its own host so subclasses pass the absolute base.
 */
abstract class GoogleBaseDriver extends AbstractDriver
{
    public function __construct(
        protected TokenStore $tokens,
        protected OAuthFlow $oauth,
    ) {}

    public function provider(): string
    {
        return 'google';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'Google Workspace';
    }

    protected function requiredConfigKeys(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function ping(): bool
    {
        if (! $this->isConfigured() || ! $this->integration?->is_enabled) {
            return false;
        }
        try {
            return Http::withToken($this->accessToken())
                ->get('https://www.googleapis.com/oauth2/v3/userinfo')
                ->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function accessToken(): string
    {
        $integration = $this->integration ?? throw new RuntimeException('Google driver not bound to integration row.');
        $token = $this->tokens->active($integration);

        if (! $token) {
            throw new RuntimeException('Google Workspace is not connected — no token on file.');
        }

        if ($token->expiresWithin(2)) {
            $this->oauth->refresh($integration);
            $token = $this->tokens->active($integration);
        }

        return $token->access_token;
    }

    protected function http(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30);
    }

    protected function get(string $base, string $path, array $query = []): Response
    {
        return $this->http($base)->get($path, $query)->throw();
    }

    protected function post(string $base, string $path, array $payload = []): Response
    {
        return $this->http($base)->post($path, $payload)->throw();
    }

    protected function patch(string $base, string $path, array $payload = []): Response
    {
        return $this->http($base)->patch($path, $payload)->throw();
    }

    protected function deleteRequest(string $base, string $path): Response
    {
        return $this->http($base)->delete($path)->throw();
    }
}

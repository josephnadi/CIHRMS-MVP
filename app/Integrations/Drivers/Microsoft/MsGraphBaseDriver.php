<?php

namespace App\Integrations\Drivers\Microsoft;

use App\Integrations\Drivers\AbstractDriver;
use App\Integrations\OAuth\OAuthFlow;
use App\Integrations\OAuth\TokenStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared HTTP client + token resolution for every Microsoft Graph driver
 * (files, spreadsheet, calendar). One OAuth grant covers all three.
 */
abstract class MsGraphBaseDriver extends AbstractDriver
{
    public function __construct(
        protected TokenStore $tokens,
        protected OAuthFlow $oauth,
    ) {}

    public function provider(): string
    {
        return 'ms_graph';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'Microsoft 365';
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
            return $this->get('/me')->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function apiBase(): string
    {
        return $this->driverConfig()['api_base'] ?? 'https://graph.microsoft.com/v1.0';
    }

    /** Authenticated HTTP client with auto-refresh on 401. */
    protected function http(): PendingRequest
    {
        $integration = $this->integration ?? throw new RuntimeException('MS Graph driver not bound to integration row.');
        $token = $this->tokens->active($integration);

        if (! $token) {
            throw new RuntimeException('Microsoft 365 is not connected — no token on file.');
        }

        if ($token->expiresWithin(2)) {
            $this->oauth->refresh($integration);
            $token = $this->tokens->active($integration);
        }

        return Http::baseUrl($this->apiBase())
            ->withToken($token->access_token)
            ->acceptJson()
            ->timeout(30);
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->http()->get($path, $query)->throw();
    }

    protected function post(string $path, array $payload = []): Response
    {
        return $this->http()->post($path, $payload)->throw();
    }

    protected function patch(string $path, array $payload = []): Response
    {
        return $this->http()->patch($path, $payload)->throw();
    }

    protected function deleteRequest(string $path): Response
    {
        return $this->http()->delete($path)->throw();
    }

    /** Raw PUT for binary uploads (OneDrive simple upload). */
    protected function putBinary(string $path, mixed $contents, ?string $mimeType = null): Response
    {
        $integration = $this->integration ?? throw new RuntimeException('MS Graph driver not bound.');
        $token = $this->tokens->active($integration);

        if ($token && $token->expiresWithin(2)) {
            $this->oauth->refresh($integration);
            $token = $this->tokens->active($integration);
        }

        return Http::baseUrl($this->apiBase())
            ->withToken($token?->access_token ?? '')
            ->withHeaders($mimeType ? ['Content-Type' => $mimeType] : [])
            ->withBody(is_resource($contents) ? stream_get_contents($contents) : (string) $contents, $mimeType ?? 'application/octet-stream')
            ->put($path)
            ->throw();
    }
}

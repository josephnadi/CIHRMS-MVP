<?php

namespace App\Integrations\Contracts;

interface IdentityProvider extends IntegrationProvider
{
    public function authorizationUrl(string $state, ?string $redirectUri = null): string;

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int, scopes: array<int,string>} */
    public function exchangeCode(string $code, ?string $redirectUri = null): array;

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function refreshAccessToken(string $refreshToken): array;
}

<?php

namespace App\Integrations\OAuth;

use App\Models\Integration;
use App\Models\IntegrationToken;

/**
 * Persists encrypted access/refresh tokens against an Integration.
 * The IntegrationToken model handles encrypted casts.
 */
class TokenStore
{
    public function store(
        Integration $integration,
        string $accessToken,
        ?string $refreshToken,
        int $expiresIn,
        array $scopes = [],
        ?int $userId = null,
    ): IntegrationToken {
        // One active token per (integration, user) pair — replace older ones.
        $integration->tokens()->where('user_id', $userId)->delete();

        return $integration->tokens()->create([
            'user_id'       => $userId,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'scopes'        => $scopes,
            'expires_at'    => now()->addSeconds($expiresIn),
        ]);
    }

    public function active(Integration $integration, ?int $userId = null): ?IntegrationToken
    {
        return $integration->tokens()
            ->where('user_id', $userId)
            ->latest()
            ->first();
    }

    public function revoke(Integration $integration, ?int $userId = null): void
    {
        $integration->tokens()->where('user_id', $userId)->delete();
    }

    /**
     * Tokens that will expire within $minutes — used by the refresh job.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, IntegrationToken>
     */
    public function expiringWithin(int $minutes = 5): \Illuminate\Database\Eloquent\Collection
    {
        return IntegrationToken::query()
            ->whereNotNull('refresh_token')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addMinutes($minutes))
            ->with('integration')
            ->get();
    }
}

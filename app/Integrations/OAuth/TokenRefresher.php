<?php

namespace App\Integrations\OAuth;

use Illuminate\Support\Facades\Log;
use Throwable;

class TokenRefresher
{
    public function __construct(
        protected TokenStore $store,
        protected OAuthFlow $flow,
    ) {}

    /** Refresh every token expiring within $minutes. Returns counts. */
    public function refreshExpiring(int $minutes = 5): array
    {
        $tokens = $this->store->expiringWithin($minutes);
        $ok = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            try {
                $this->flow->refresh($token->integration, $token->user_id);
                $ok++;
            } catch (Throwable $e) {
                Log::warning("[integrations] token refresh failed for {$token->integration?->provider}", [
                    'integration_id' => $token->integration_id,
                    'user_id'        => $token->user_id,
                    'error'          => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['checked' => $tokens->count(), 'refreshed' => $ok, 'failed' => $failed];
    }
}

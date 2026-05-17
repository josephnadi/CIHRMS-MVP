<?php

namespace App\Services\Api;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Public-API webhook dispatcher.
 *
 * Operates on the canonical `webhook_subscriptions` schema (signing_secret /
 * target_url / event_types / consecutive_failures). HMAC-SHA256 signs
 * "{timestamp}.{body}" with the subscription's secret; receivers verify
 * with the same formula. Ten consecutive failures auto-deactivate the
 * subscription.
 *
 * Note: this lives alongside \App\Services\Webhooks\WebhookDispatcher
 * (event-listener style) — both target the same table; this one is
 * dispatched directly from API controllers when an explicit fire-now
 * semantic is wanted (e.g. test hooks).
 */
class WebhookDispatcher
{
    public const AUTO_DEACTIVATE_AT_FAILURES = 10;
    public const TIMEOUT_SECONDS = 8;

    public function dispatch(string $event, array $payload): int
    {
        $subscribers = WebhookSubscription::active()->get()
            ->filter(fn (WebhookSubscription $s) => $s->subscribesTo($event));

        $delivered = 0;
        foreach ($subscribers as $sub) {
            if ($this->deliver($sub, $event, $payload)) $delivered++;
        }
        return $delivered;
    }

    private function deliver(WebhookSubscription $sub, string $event, array $payload): bool
    {
        $body = [
            'event'       => $event,
            'event_id'    => (string) \Illuminate\Support\Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
            'data'        => $payload,
        ];
        $bodyJson  = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $signature = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$bodyJson}", (string) $sub->signing_secret);

        $delivery = WebhookDelivery::create([
            'subscription_id' => $sub->id,
            'event_type'      => $event,
            'payload'         => $body,
            'status'          => 'pending',
            'attempted_at'    => now(),
        ]);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'Content-Type'         => 'application/json',
                    'X-CIHRMS-Event'       => $event,
                    'X-CIHRMS-Timestamp'   => $timestamp,
                    'X-CIHRMS-Signature'   => $signature,
                    'X-CIHRMS-Delivery-Id' => (string) $delivery->id,
                ])
                ->withBody($bodyJson, 'application/json')
                ->post($sub->target_url);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed']);
            $this->recordFailure($sub);
            return false;
        }

        $isSuccess = $response->successful();
        $delivery->update([
            'status'        => $isSuccess ? 'delivered' : 'failed',
            'response_code' => $response->status(),
            'response_body' => substr($response->body(), 0, 2000),
            'delivered_at'  => $isSuccess ? now() : null,
        ]);

        if ($isSuccess) {
            $sub->update(['last_success_at' => now(), 'consecutive_failures' => 0]);
            return true;
        }

        $this->recordFailure($sub);
        return false;
    }

    private function recordFailure(WebhookSubscription $sub): void
    {
        $sub->increment('consecutive_failures');
        $sub->update(['last_failure_at' => now()]);
        if ($sub->consecutive_failures >= self::AUTO_DEACTIVATE_AT_FAILURES) {
            $sub->update(['is_active' => false]);
            Log::warning("Auto-deactivated webhook subscription #{$sub->id} after {$sub->consecutive_failures} failures.");
        }
    }
}

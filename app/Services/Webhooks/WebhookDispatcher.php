<?php

namespace App\Services\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Webhook fan-out. For each event payload, finds active subscriptions that
 * match the event type, signs the body with the subscription's HMAC secret,
 * and POSTs to the target URL. Failures are logged for retry; ten consecutive
 * failures auto-disable the subscription.
 */
class WebhookDispatcher
{
    public const MAX_CONSECUTIVE_FAILURES = 10;

    public function fanOut(string $eventType, array $payload): int
    {
        $subscribers = WebhookSubscription::active()->get()->filter->subscribesTo($eventType);
        $dispatched = 0;

        foreach ($subscribers as $sub) {
            $this->deliver($sub, $eventType, $payload);
            $dispatched++;
        }

        return $dispatched;
    }

    public function deliver(WebhookSubscription $sub, string $eventType, array $payload): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'subscription_id' => $sub->id,
            'event_type'      => $eventType,
            'payload'         => $payload,
            'attempt'         => 1,
            'status'          => 'retrying',
            'attempted_at'    => now(),
        ]);

        $envelope = [
            'id'         => (string) Str::uuid(),
            'type'       => $eventType,
            'created_at' => now()->toIso8601String(),
            'data'       => $payload,
        ];
        $body = json_encode($envelope);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $sub->signing_secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type'         => 'application/json',
                    'X-CIHRMS-Event'       => $eventType,
                    'X-CIHRMS-Signature'   => $signature,
                    'X-CIHRMS-Delivery'    => $envelope['id'],
                ])
                ->withBody($body, 'application/json')
                ->post($sub->target_url);

            if ($response->successful()) {
                $delivery->update([
                    'status'        => 'delivered',
                    'response_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 1000),
                    'delivered_at'  => now(),
                ]);
                $sub->update([
                    'last_success_at'       => now(),
                    'consecutive_failures'  => 0,
                ]);
                return $delivery;
            }

            $delivery->update([
                'status'        => 'failed',
                'response_code' => $response->status(),
                'response_body' => substr($response->body(), 0, 1000),
            ]);
            $this->recordFailure($sub);
        } catch (\Throwable $e) {
            $delivery->update([
                'status'        => 'failed',
                'response_body' => substr($e->getMessage(), 0, 1000),
            ]);
            $this->recordFailure($sub);
        }

        return $delivery;
    }

    private function recordFailure(WebhookSubscription $sub): void
    {
        $sub->increment('consecutive_failures');
        $sub->update(['last_failure_at' => now()]);

        if ($sub->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
            $sub->update(['is_active' => false]);
        }
    }
}

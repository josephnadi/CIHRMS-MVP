<?php

namespace App\Http\Middleware;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify inbound webhook signatures per provider.
 *
 * Use as: Route::post('/webhooks/whatsapp', ...)->middleware('webhook.signature:whatsapp');
 *
 * On verification failure the request is rejected with 401 and the failed
 * payload is logged into integration_events with status=failed for forensics.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next, string $provider): Response
    {
        $verified = match ($provider) {
            'whatsapp' => $this->verifyWhatsApp($request),
            'zoho'     => $this->verifyZoho($request),
            'ms_graph' => $this->verifyMsGraph($request),
            'google'   => $this->verifyGoogle($request),
            'slack'    => $this->verifySlack($request),
            default    => false,
        };

        if (! $verified) {
            $this->logFailure($provider, $request);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }

    protected function verifyWhatsApp(Request $request): bool
    {
        // Meta GET verification handshake (one-time)
        if ($request->isMethod('get')) {
            $verifyToken = config('integrations.webhooks.whatsapp.verify_token');

            return $request->query('hub_verify_token') === $verifyToken;
        }

        $signature = $request->header('X-Hub-Signature-256');
        $secret    = config('integrations.webhooks.whatsapp.app_secret');

        if (! $signature || ! $secret) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    protected function verifyZoho(Request $request): bool
    {
        $secret = config('integrations.webhooks.zoho.shared_secret');
        $header = config('integrations.webhooks.zoho.header', 'X-Zoho-Webhook-Token');
        if (! $secret) {
            return false;
        }

        return hash_equals($secret, (string) $request->header($header));
    }

    protected function verifyMsGraph(Request $request): bool
    {
        // Subscription validation handshake
        if ($validation = $request->query('validationToken')) {
            // We must echo it back as text/plain — handled at the controller level via `validationToken` short-circuit.
            // For middleware purposes, accept the request so the controller can respond.
            return true;
        }

        $expected = config('integrations.webhooks.ms_graph.client_state');
        if (! $expected) {
            return false;
        }

        $payload = $request->json()->all();
        $values  = data_get($payload, 'value.*.clientState', []);

        if (! is_array($values) || $values === []) {
            return false;
        }

        return collect($values)->every(fn ($v) => hash_equals($expected, (string) $v));
    }

    protected function verifyGoogle(Request $request): bool
    {
        $expected = config('integrations.webhooks.google.channel_token');
        if (! $expected) {
            return false;
        }

        return hash_equals($expected, (string) $request->header('X-Goog-Channel-Token'));
    }

    protected function verifySlack(Request $request): bool
    {
        $secret = config('integrations.webhooks.slack.signing_secret');
        if (! $secret) {
            return false;
        }

        $timestamp = (string) $request->header('X-Slack-Request-Timestamp', '0');
        if (abs(time() - (int) $timestamp) > 300) {
            return false; // replay protection: reject stale requests
        }

        $base      = "v0:{$timestamp}:".$request->getContent();
        $expected  = 'v0='.hash_hmac('sha256', $base, $secret);
        $signature = (string) $request->header('X-Slack-Signature');

        return $signature !== '' && hash_equals($expected, $signature);
    }

    protected function logFailure(string $provider, Request $request): void
    {
        $integration = Integration::query()->where('provider', $this->mapProvider($provider))->first();
        if (! $integration) {
            return;
        }

        IntegrationEvent::create([
            'integration_id' => $integration->id,
            'direction'      => IntegrationEvent::DIRECTION_INBOUND,
            'event_type'     => 'webhook.signature_invalid',
            'payload'        => $request->json()->all() ?: ['raw' => substr($request->getContent(), 0, 2000)],
            'status'         => IntegrationEvent::STATUS_FAILED,
            'error'          => 'Signature verification failed',
        ]);
    }

    protected function mapProvider(string $key): string
    {
        return match ($key) {
            'whatsapp' => 'whatsapp_cloud',
            default    => $key,
        };
    }
}

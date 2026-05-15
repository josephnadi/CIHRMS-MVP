<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Generic inbound webhook receiver.
 *
 * Wave 9 ships only the receive-and-log scaffolding. Per-provider parsing
 * (e.g. WhatsApp message routing, Zoho contact diff, MS Graph subscription
 * change notifications) is added in Waves 10-12 with dedicated controllers
 * extending this one.
 *
 * Pattern (per Meta best practice): respond 200 immediately, push processing
 * onto the integrations-inbound queue.
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $provider): Response|JsonResponse
    {
        // Microsoft Graph subscription validation handshake
        if ($validation = $request->query('validationToken')) {
            return response((string) $validation, 200, ['Content-Type' => 'text/plain']);
        }

        // Meta GET handshake
        if ($request->isMethod('get') && $request->has('hub_challenge')) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        $integration = Integration::query()
            ->where('provider', $this->mapProvider($provider))
            ->first();

        $event = $integration
            ? IntegrationEvent::create([
                'integration_id' => $integration->id,
                'direction'      => IntegrationEvent::DIRECTION_INBOUND,
                'event_type'     => 'webhook.received',
                'payload'        => $request->json()->all() ?: ['raw' => $request->getContent()],
                'status'         => IntegrationEvent::STATUS_RECEIVED,
                'processed_at'   => now(),
            ])
            : null;

        // Concrete dispatch (queue jobs to parse + react) is wired in later waves.
        // Wave 10+: dispatch(new ProcessInboundWebhook($event))->onQueue('integrations-inbound');

        return response()->json(['ok' => true, 'event_id' => $event?->id], 200);
    }

    protected function mapProvider(string $key): string
    {
        return match ($key) {
            'whatsapp' => 'whatsapp_cloud',
            default    => $key,
        };
    }
}

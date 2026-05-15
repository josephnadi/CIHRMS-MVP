<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\EnvelopeStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Single endpoint for both Zoho Sign and DocuSign envelope-status callbacks.
 *
 * Provider is detected from the payload shape:
 *   - Zoho Sign sends `requests.request_id` + `requests.request_status`
 *   - DocuSign sends `envelopeId` + `status` (when configured with eventNotification)
 *
 * Both providers' envelope IDs are unique within their tenant — the Applicant row
 * stores `esign_provider` so we know which one we're matching.
 */
class ESignWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all() ?: [];

        [$envelopeId, $newStatus, $providerKey] = $this->detect($payload);

        $integration = $providerKey
            ? Integration::query()->where('provider', $providerKey)->first()
            : null;

        $event = $integration
            ? IntegrationEvent::create([
                'integration_id' => $integration->id,
                'direction'      => IntegrationEvent::DIRECTION_INBOUND,
                'event_type'     => 'esign.envelope.status',
                'external_id'    => $envelopeId,
                'payload'        => $payload,
                'status'         => IntegrationEvent::STATUS_RECEIVED,
                'processed_at'   => now(),
            ])
            : null;

        if (! $envelopeId) {
            return response()->json(['ok' => true, 'note' => 'no envelope id in payload'], 200);
        }

        try {
            $applicant = Applicant::query()->where('esign_envelope_id', $envelopeId)->first();
            if (! $applicant) {
                return response()->json(['ok' => true, 'note' => 'no applicant matched'], 200);
            }

            $previous = $applicant->esign_status;
            $normalised = $this->normaliseStatus($newStatus);

            $applicant->update([
                'esign_status'        => $normalised,
                'esign_completed_at'  => $normalised === 'completed' ? now() : $applicant->esign_completed_at,
            ]);

            EnvelopeStatusChanged::dispatch($applicant, $normalised, $previous, $payload);
        } catch (\Throwable $e) {
            Log::warning('[integrations] e-sign status update failed', ['error' => $e->getMessage()]);
            $event?->markFailed($e->getMessage());

            return response()->json(['ok' => false], 200);
        }

        return response()->json(['ok' => true, 'event_id' => $event?->id], 200);
    }

    /** @return array{0:?string,1:?string,2:?string} envelopeId, status, provider */
    protected function detect(array $payload): array
    {
        if ($id = data_get($payload, 'requests.request_id')) {
            return [(string) $id, (string) data_get($payload, 'requests.request_status'), 'zoho_sign'];
        }
        if ($id = data_get($payload, 'envelopeId')) {
            return [(string) $id, (string) data_get($payload, 'status'), 'docusign'];
        }
        // Connect-style DocuSign payload nests under `data.envelopeSummary`.
        if ($id = data_get($payload, 'data.envelopeSummary.envelopeId')) {
            return [(string) $id, (string) data_get($payload, 'data.envelopeSummary.status'), 'docusign'];
        }
        return [null, null, null];
    }

    protected function normaliseStatus(?string $raw): string
    {
        return match (strtolower((string) $raw)) {
            'completed', 'signed'         => 'completed',
            'declined'                    => 'declined',
            'voided', 'recalled', 'expired' => 'voided',
            'delivered', 'viewed', 'inprogress' => 'viewed',
            'sent'                        => 'sent',
            default                       => $raw ?: 'unknown',
        };
    }
}

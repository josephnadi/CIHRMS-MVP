<?php

namespace App\Listeners;

use App\Events\OfferEnvelopeRequested;
use App\Integrations\DTO\EnvelopeDto;
use App\Integrations\IntegrationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Take the rendered offer-letter PDF and dispatch it to the configured e-sign provider
 * (Zoho Sign by default; flip INT_ESIGN_DRIVER=docusign for DocuSign). Stores the
 * envelope id + sent timestamp back onto the Applicant row so the UI can poll status.
 */
class SendOfferEnvelopeToApplicant implements ShouldQueue
{
    public string $queue = 'integrations';

    public function __construct(protected IntegrationManager $integrations) {}

    public function handle(OfferEnvelopeRequested $event): void
    {
        if (! $this->integrations->isAvailable('esign')) {
            Log::warning('[integrations] e-sign provider not configured; skipping offer envelope', [
                'applicant_id' => $event->applicant->id,
            ]);
            return;
        }

        try {
            $esign = $this->integrations->for('esign');

            $envelope = new EnvelopeDto(
                subject:        $event->subject,
                message:        $event->message,
                documentBase64: $event->pdfBase64,
                documentName:   $event->documentName,
                recipients:     [[
                    'email' => $event->applicant->email,
                    'name'  => $event->applicant->name,
                    'role'  => 'Candidate',
                ]],
                callbackUrl:    route('webhooks.esign'),
            );

            $envelopeId = $esign->createEnvelope($envelope);

            $event->applicant->update([
                'esign_provider'    => $esign->provider(),
                'esign_envelope_id' => $envelopeId,
                'esign_status'      => 'sent',
                'esign_sent_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[integrations] offer envelope send failed', [
                'applicant_id' => $event->applicant->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Events;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from Recruitment/Show.vue's "Send offer via e-sign" action.
 * Carries the rendered offer-letter PDF (base64) so the listener can stream
 * it to Zoho Sign or DocuSign without touching the disk.
 */
class OfferEnvelopeRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Applicant $applicant,
        public readonly string $pdfBase64,
        public readonly string $documentName,
        public readonly string $subject,
        public readonly string $message,
        public readonly ?User $actor = null,
    ) {}
}

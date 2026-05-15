<?php

namespace App\Events;

use App\Models\Applicant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by the e-sign webhook handlers when an envelope's status changes
 * (sent → viewed → completed / declined / voided). Downstream listeners can
 * notify the applicant or update the recruitment pipeline.
 */
class EnvelopeStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Applicant $applicant,
        public readonly string $newStatus,
        public readonly ?string $previousStatus = null,
        public readonly array $rawPayload = [],
    ) {}
}

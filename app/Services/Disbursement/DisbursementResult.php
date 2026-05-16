<?php

namespace App\Services\Disbursement;

use App\Enums\DisbursementStatus;

final class DisbursementResult
{
    public function __construct(
        public readonly DisbursementStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {}

    public static function sent(string $reference, array $raw = []): self
    {
        return new self(DisbursementStatus::Sent, $reference, null, $raw);
    }

    public static function settled(string $reference, array $raw = []): self
    {
        return new self(DisbursementStatus::Settled, $reference, null, $raw);
    }

    public static function failed(string $reason, array $raw = []): self
    {
        return new self(DisbursementStatus::Failed, null, $reason, $raw);
    }
}

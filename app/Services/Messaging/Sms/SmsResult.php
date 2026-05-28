<?php

namespace App\Services\Messaging\Sms;

final class SmsResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
        public readonly int $segments = 1,
        public readonly float $cost = 0.0,
        public readonly array $raw = [],
        public readonly bool $retryable = false,
    ) {}

    public static function sent(string $messageId, int $segments = 1, float $cost = 0.0, array $raw = []): self
    {
        return new self(true, $messageId, null, $segments, $cost, $raw, false);
    }

    /** Permanent failure — bad input, auth error, blocked content. Do not retry. */
    public static function failed(string $reason, array $raw = []): self
    {
        return new self(false, null, $reason, 1, 0.0, $raw, false);
    }

    /** Transient failure — network, 5xx, timeout. Worth retrying. */
    public static function failedTransient(string $reason, array $raw = []): self
    {
        return new self(false, null, $reason, 1, 0.0, $raw, true);
    }
}

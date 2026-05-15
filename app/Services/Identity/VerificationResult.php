<?php

namespace App\Services\Identity;

/**
 * Immutable result of a Ghana Card verification attempt.
 */
final class VerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $reason = null,
        public readonly array $raw = [],
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {}

    public static function ok(array $raw = [], ?\DateTimeImmutable $expiresAt = null): self
    {
        return new self(true, null, $raw, $expiresAt);
    }

    public static function failed(string $reason, array $raw = []): self
    {
        return new self(false, $reason, $raw);
    }
}

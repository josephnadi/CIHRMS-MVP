<?php

declare(strict_types=1);

namespace App\Services\Finance\Posting;

use DomainException;

final class PostingLine
{
    public function __construct(
        public readonly ?int $accountId,
        public readonly ?string $accountSlug,
        public readonly float $debit,
        public readonly float $credit,
        public readonly ?string $narration = null,
    ) {
        if (($accountId === null) === ($accountSlug === null)) {
            throw new DomainException('PostingLine requires exactly one of accountId or accountSlug.');
        }
        if ($debit < 0 || $credit < 0) {
            throw new DomainException('PostingLine amounts must be non-negative.');
        }
        if (($debit > 0) === ($credit > 0)) {
            throw new DomainException('PostingLine must carry exactly one of a positive debit or credit.');
        }
    }

    public static function debit(float $amount, ?string $slug = null, ?int $accountId = null, ?string $narration = null): self
    {
        return new self($accountId, $slug, $amount, 0.0, $narration);
    }

    public static function credit(float $amount, ?string $slug = null, ?int $accountId = null, ?string $narration = null): self
    {
        return new self($accountId, $slug, 0.0, $amount, $narration);
    }
}

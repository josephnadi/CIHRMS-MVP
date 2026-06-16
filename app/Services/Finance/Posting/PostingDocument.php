<?php

declare(strict_types=1);

namespace App\Services\Finance\Posting;

use App\Enums\JournalSourceType;
use DomainException;

final class PostingDocument
{
    /** @param PostingLine[] $lines */
    public function __construct(
        public readonly JournalSourceType $sourceType,
        public readonly ?int $sourceId,
        public readonly string $purpose,
        public readonly string $date,
        public readonly string $narration,
        public readonly array $lines,
        public readonly string $currency = 'GHS',
    ) {
        if (count($lines) < 2) {
            throw new DomainException('A posting document needs at least two lines.');
        }
        foreach ($lines as $line) {
            if (! $line instanceof PostingLine) {
                throw new DomainException('Every posting line must be a PostingLine instance.');
            }
        }
    }

    public function isBalanced(): bool
    {
        $dr = array_sum(array_map(fn (PostingLine $l) => $l->debit, $this->lines));
        $cr = array_sum(array_map(fn (PostingLine $l) => $l->credit, $this->lines));

        return abs($dr - $cr) < 0.005;
    }
}

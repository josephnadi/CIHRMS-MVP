<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalEntryStatus;
use App\Exceptions\Finance\AlreadyPostedException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single pathway every business event uses to reach the General Ledger.
 * Builds a JournalEntry from a PostingDocument, resolves account references
 * (slug -> GL via AccountResolver, or literal id), enforces idempotency on
 * (source_type, source_id, source_purpose), and delegates the actual balance
 * mutation to JournalPostingService::post().
 */
class PostingService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly AccountResolver $resolver,
        private readonly SequenceService $sequences,
        private readonly PostingActorResolver $actors,
    ) {
    }

    public function post(PostingDocument $doc, ?User $actor = null): JournalEntry
    {
        // Fail-loud and fast: reject an unbalanced document before writing any
        // rows. (JournalPostingService::post() also guards this, but checking
        // up front gives a clearer error and avoids the create+rollback churn.)
        if (! $doc->isBalanced()) {
            throw new DomainException('PostingDocument is not balanced: total debits must equal total credits.');
        }

        // Idempotency (only for identifiable sources). A null source_id is a
        // one-off (manual / ad-hoc) entry and is never deduplicated.
        if ($doc->sourceId !== null) {
            $existing = JournalEntry::query()
                ->where('source_type', $doc->sourceType->value)
                ->where('source_id', $doc->sourceId)
                ->where('source_purpose', $doc->purpose)
                ->first();

            if ($existing) {
                return $existing->load('lines.glAccount');
            }
        }

        try {
            return DB::transaction(function () use ($doc, $actor) {
                $entry = JournalEntry::create([
                    'reference'      => $this->nextReference(),
                    'entry_date'     => $doc->date,
                    'narration'      => $doc->narration,
                    'status'         => JournalEntryStatus::Draft->value,
                    'source_type'    => $doc->sourceType->value,
                    'source_id'      => $doc->sourceId,
                    'source_purpose' => $doc->purpose,
                    'created_by'     => $this->actors->resolveId($actor),
                ]);

                $lineNo = 1;
                foreach ($doc->lines as $line) {
                    $accountId = $line->accountId ?? $this->resolver->resolve($line->accountSlug)->id;

                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'line_no'          => $lineNo++,
                        'gl_account_id'    => $accountId,
                        'debit_amount'     => $line->debit,
                        'credit_amount'    => $line->credit,
                        'narration'        => $line->narration,
                    ]);
                }

                return $this->journal->post($entry->fresh('lines.glAccount'), $actor);
            });
        } catch (QueryException $e) {
            // ONLY a lost race on the idempotency unique index is benign. Any
            // other DB error (FK, NOT NULL, etc.) must surface unchanged with
            // its real type — never relabelled as AlreadyPosted.
            $isIdempotencyRace = $doc->sourceId !== null
                && str_contains($e->getMessage(), 'journal_entries_source_idem_unique');

            if (! $isIdempotencyRace) {
                throw $e;
            }

            $existing = JournalEntry::query()
                ->where('source_type', $doc->sourceType->value)
                ->where('source_id', $doc->sourceId)
                ->where('source_purpose', $doc->purpose)
                ->first();

            if ($existing) {
                return $existing->load('lines.glAccount');
            }

            throw new AlreadyPostedException($e->getMessage(), 0, $e);
        }
    }

    public function reverseFor(
        \App\Enums\JournalSourceType $type,
        int $sourceId,
        string $purpose,
        User $by,
        string $reason,
    ): JournalEntry {
        $entry = JournalEntry::query()
            ->where('source_type', $type->value)
            ->where('source_id', $sourceId)
            ->where('source_purpose', $purpose)
            ->where('status', JournalEntryStatus::Posted->value)
            ->firstOrFail();

        return $this->journal->reverse($entry, $by, $reason);
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');

        return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
    }
}

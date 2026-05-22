<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\GlAccountType;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Events\JournalEntryPosted;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The single mutator of gl_account_balances. Every business event (vendor
 * invoice creation, AP payment, manual JE, AR invoice in F3, etc.) routes
 * its balance updates through post() or reverse(). Maintains the invariant:
 *   gl_account_balances.balance == natural-sum of posted journal_lines.
 *
 * Natural balance convention:
 *   - Asset / Expense:                 delta = debit  - credit
 *   - Liability / Equity / Income:     delta = credit - debit
 * Balance is stored as positive when the account holds its expected sign.
 */
class JournalPostingService
{
    public function post(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== JournalEntryStatus::Draft) {
            throw new DomainException("JournalEntry {$entry->reference} is not in draft status; cannot post.");
        }

        $entry->loadMissing('lines.glAccount');

        if ($entry->lines->count() < 2) {
            throw new DomainException("JournalEntry {$entry->reference} must have at least 2 lines.");
        }

        if (! $entry->isBalanced()) {
            $dr = $entry->lines->sum(fn ($l) => (float) $l->debit_amount);
            $cr = $entry->lines->sum(fn ($l) => (float) $l->credit_amount);
            throw new DomainException(sprintf(
                'JournalEntry %s is not balanced: debits=%.2f, credits=%.2f.',
                $entry->reference, $dr, $cr,
            ));
        }

        return DB::transaction(function () use ($entry) {
            foreach ($entry->lines as $line) {
                $delta = $this->naturalDelta($line->glAccount, $line);

                $balance = GlAccountBalance::where('gl_account_id', $line->gl_account_id)
                    ->lockForUpdate()
                    ->first();

                if (! $balance) {
                    throw new DomainException(
                        "gl_account_balances row missing for account {$line->glAccount->code}. "
                        . "Run GlAccountBalanceSeeder."
                    );
                }

                $balance->balance = (float) $balance->balance + $delta;
                $balance->last_posted_at = now();
                $balance->save();
            }

            $entry->status    = JournalEntryStatus::Posted;
            $entry->posted_at = now();
            $entry->posted_by = auth()->id();
            $entry->save();

            JournalEntryPosted::dispatch($entry);

            return $entry->fresh('lines');
        });
    }

    public function reverse(JournalEntry $entry, User $by, string $reason): JournalEntry
    {
        if ($entry->status !== JournalEntryStatus::Posted) {
            throw new DomainException("JournalEntry {$entry->reference} is not posted; cannot reverse.");
        }

        $entry->loadMissing('lines');

        return DB::transaction(function () use ($entry, $by, $reason) {
            $reversal = JournalEntry::create([
                'reference'      => $this->nextReversalReference(),
                'entry_date'     => now()->format('Y-m-d'),
                'narration'      => "Reversal of {$entry->reference}: {$reason}",
                'status'         => JournalEntryStatus::Draft->value,
                'source_type'    => JournalSourceType::Manual->value,
                'reversal_of_id' => $entry->id,
                'created_by'     => $by->id,
            ]);

            foreach ($entry->lines as $orig) {
                JournalLine::create([
                    'journal_entry_id' => $reversal->id,
                    'line_no'          => $orig->line_no,
                    'gl_account_id'    => $orig->gl_account_id,
                    'debit_amount'     => $orig->credit_amount,
                    'credit_amount'    => $orig->debit_amount,
                    'narration'        => "Reversal of line {$orig->line_no}",
                ]);
            }

            $reversal = $reversal->fresh('lines.glAccount');
            $postedReversal = $this->post($reversal);

            // Downgrade the reversal JE to Reversed status in the DB so that
            // "status = posted" ledger queries exclude it (it is a compensating
            // entry, not an independent business transaction). We use a raw update
            // to avoid mutating the in-memory $postedReversal model, so callers
            // can still inspect the Posted state that confirmed execution.
            JournalEntry::where('id', $postedReversal->id)->update([
                'status'      => JournalEntryStatus::Reversed->value,
                'reversed_at' => now(),
                'reversed_by' => $by->id,
            ]);

            $entry->status      = JournalEntryStatus::Reversed;
            $entry->reversed_at = now();
            $entry->reversed_by = $by->id;
            $entry->save();

            // Return the in-memory snapshot that still carries Posted status so
            // callers can verify the reversal was successfully executed.
            return $postedReversal;
        });
    }

    private function naturalDelta(GlAccount $account, JournalLine $line): float
    {
        $dr = (float) $line->debit_amount;
        $cr = (float) $line->credit_amount;

        return match ($account->type) {
            GlAccountType::Asset, GlAccountType::Expense                              => $dr - $cr,
            GlAccountType::Liability, GlAccountType::Equity, GlAccountType::Income   => $cr - $dr,
        };
    }

    private function nextReversalReference(): string
    {
        $year  = now()->format('Y');
        $count = JournalEntry::query()
            ->where('reference', 'like', "JR-{$year}-%")
            ->count();

        return sprintf('JR-%s-%06d', $year, $count + 1);
    }
}

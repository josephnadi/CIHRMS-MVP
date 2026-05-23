<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BankAdjustmentService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly ReconciliationService $reconciliation,
    ) {
    }

    public function postAdjustment(
        BankStatementLine $line,
        GlAccount $offsetGl,
        User $user,
        string $narration,
    ): JournalEntry {
        $bankGl = $line->statement->orgBankAccount->glAccount;
        $abs    = abs((float) $line->amount);

        return DB::transaction(function () use ($line, $offsetGl, $bankGl, $abs, $user, $narration) {
            $je = JournalEntry::create([
                'reference'   => $this->nextJournalReference(),
                'entry_date'  => $line->transaction_date->format('Y-m-d'),
                'narration'   => $narration,
                'status'      => JournalEntryStatus::Draft->value,
                'source_type' => JournalSourceType::BankAdjustment->value,
                'source_id'   => $line->id,
                'created_by'  => $user->id,
            ]);

            // Debit line (fee): Dr offsetGl, Cr bank
            // Credit line (interest): Dr bank, Cr offsetGl
            if ($line->isDebit()) {
                JournalLine::create([
                    'journal_entry_id' => $je->id, 'line_no' => 1,
                    'gl_account_id' => $offsetGl->id,
                    'debit_amount'  => $abs, 'credit_amount' => 0,
                    'narration' => $narration,
                ]);
                JournalLine::create([
                    'journal_entry_id' => $je->id, 'line_no' => 2,
                    'gl_account_id' => $bankGl->id,
                    'debit_amount'  => 0, 'credit_amount' => $abs,
                    'narration' => $narration,
                ]);
            } else {
                JournalLine::create([
                    'journal_entry_id' => $je->id, 'line_no' => 1,
                    'gl_account_id' => $bankGl->id,
                    'debit_amount'  => $abs, 'credit_amount' => 0,
                    'narration' => $narration,
                ]);
                JournalLine::create([
                    'journal_entry_id' => $je->id, 'line_no' => 2,
                    'gl_account_id' => $offsetGl->id,
                    'debit_amount'  => 0, 'credit_amount' => $abs,
                    'narration' => $narration,
                ]);
            }

            $this->journal->post($je->fresh('lines.glAccount'));

            $this->reconciliation->link($line, $je->fresh(), $user, 'manual');

            return $je->fresh();
        });
    }

    private function nextJournalReference(): string
    {
        $year = now()->format('Y');
        $count = JournalEntry::query()->where('reference', 'like', "JE-{$year}-%")->count();
        return sprintf('JE-%s-%06d', $year, $count + 1);
    }
}

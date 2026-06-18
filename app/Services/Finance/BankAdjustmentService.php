<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalSourceType;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Illuminate\Support\Facades\DB;

class BankAdjustmentService
{
    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly PostingService $posting,
    ) {
    }

    public function postAdjustment(
        BankStatementLine $line,
        GlAccount $offsetGl,
        User $user,
        string $narration,
    ): JournalEntry {
        $bankGl = $line->statement->orgBankAccount->glAccount;

        return DB::transaction(function () use ($line, $offsetGl, $bankGl, $user, $narration) {
            $abs = abs((float) $line->amount);

            $postingLines = $line->isDebit()
                ? [
                    PostingLine::debit(amount: $abs, accountId: (int) $offsetGl->id, narration: $narration),
                    PostingLine::credit(amount: $abs, accountId: (int) $bankGl->id, narration: $narration),
                ]
                : [
                    PostingLine::debit(amount: $abs, accountId: (int) $bankGl->id, narration: $narration),
                    PostingLine::credit(amount: $abs, accountId: (int) $offsetGl->id, narration: $narration),
                ];

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::BankAdjustment,
                sourceId: $line->id,
                purpose: '',
                date: $line->transaction_date->format('Y-m-d'),
                narration: $narration,
                lines: $postingLines,
            ), $user);

            $this->reconciliation->link($line, $je->fresh(), $user, 'manual');

            return $je;
        });
    }
}

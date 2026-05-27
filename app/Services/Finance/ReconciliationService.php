<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\BankStatementLine;
use App\Models\BankTransactionMatch;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function link(BankStatementLine $line, Model $target, User $user, string $confidence): BankTransactionMatch
    {
        if ($line->reconciled_at !== null) {
            throw new DomainException("line {$line->id} is already reconciled");
        }

        return DB::transaction(function () use ($line, $target, $user, $confidence) {
            // L9 audit fix: write the matcher's identity onto the line itself,
            // not just the (potentially-deletable) BankTransactionMatch row.
            $line->update([
                'matched_type'  => get_class($target),
                'matched_id'    => $target->getKey(),
                'confidence'    => $confidence,
                'reconciled_at' => now(),
                'matched_by'    => $user->id,
                'matched_at'    => now(),
            ]);

            if ($target instanceof \App\Models\ApPayment && empty($target->external_ref) && ! empty($line->reference)) {
                $target->update(['external_ref' => $line->reference]);
            }

            return BankTransactionMatch::create([
                'bank_statement_line_id' => $line->id,
                'matched_type'           => get_class($target),
                'matched_id'             => $target->getKey(),
                'confidence'             => $confidence,
                'matched_by'             => $user->id,
                'matched_at'             => now(),
            ]);
        });
    }

    public function unlink(BankStatementLine $line, User $user, string $reason): void
    {
        if ($line->reconciled_at === null) {
            throw new DomainException("line {$line->id} is not currently reconciled");
        }

        DB::transaction(function () use ($line, $user, $reason) {
            $match = BankTransactionMatch::where('bank_statement_line_id', $line->id)
                ->whereNull('unmatched_at')
                ->orderByDesc('id')
                ->first();

            if ($match !== null) {
                $match->update([
                    'unmatched_at'     => now(),
                    'unmatched_by'     => $user->id,
                    'unmatched_reason' => $reason,
                ]);
            }

            $line->update([
                'matched_type'  => null,
                'matched_id'    => null,
                'confidence'    => null,
                'reconciled_at' => null,
            ]);
        });
    }
}

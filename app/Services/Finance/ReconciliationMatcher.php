<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\ApPayment;
use App\Models\ArReceipt;
use App\Models\BankStatement;
use App\Models\BankStatementLine;

class ReconciliationMatcher
{
    public function __construct(private readonly ReconciliationService $reconciliation)
    {
    }

    /**
     * @return array{high:int, medium:int, low:int, unmatched:int}
     */
    public function matchUnreconciled(BankStatement $statement): array
    {
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0, 'unmatched' => 0];

        $lines = $statement->lines()->unreconciled()->orderBy('line_no')->get();
        $importer = $statement->importer;

        foreach ($lines as $line) {
            $matched = $this->tryTier1($line, $statement, $importer);
            if ($matched) { $counts['high']++; continue; }

            $matched = $this->tryTier2($line, $statement, $importer);
            if ($matched) { $counts['medium']++; continue; }

            $tier3 = $this->tryTier3($line, $statement, $importer);
            if ($tier3 === 'linked') { $counts['low']++; continue; }
            if ($tier3 === 'ambiguous') { $line->update(['confidence' => 'low']); $counts['low']++; continue; }

            $counts['unmatched']++;
        }

        return $counts;
    }

    private function tryTier1(BankStatementLine $line, BankStatement $stmt, $importer): bool
    {
        $ref = $line->reference;
        $desc = $line->description;

        if ($line->isCredit()) {
            $candidates = ArReceipt::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereNotNull('external_ref')
                ->where(function ($q) use ($ref, $desc) {
                    if ($ref) {
                        $q->where('external_ref', $ref);
                    }
                    if ($desc) {
                        $q->orWhereRaw('? LIKE \'%\' || external_ref || \'%\'', [$desc]);
                    }
                })
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_id')->from('bank_statement_lines')
                        ->where('matched_type', ArReceipt::class)
                        ->whereNotNull('matched_id');
                })
                ->get();

            if ($candidates->count() === 1) {
                $this->reconciliation->link($line, $candidates->first(), $importer, 'high');
                return true;
            }
            return false;
        }

        $candidates = ApPayment::where('org_bank_account_id', $stmt->org_bank_account_id)
            ->whereNotNull('external_ref')
            ->where(function ($q) use ($ref, $desc) {
                if ($ref) {
                    $q->where('external_ref', $ref);
                }
                if ($desc) {
                    $q->orWhereRaw('? LIKE \'%\' || external_ref || \'%\'', [$desc]);
                }
            })
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ApPayment::class)
                    ->whereNotNull('matched_id');
            })
            ->get();

        if ($candidates->count() === 1) {
            $this->reconciliation->link($line, $candidates->first(), $importer, 'high');
            return true;
        }

        return false;
    }

    private function tryTier2(BankStatementLine $line, BankStatement $stmt, $importer): bool
    {
        $absAmount = abs((float) $line->amount);
        $dateFrom  = $line->transaction_date->copy()->subDays(2);
        $dateTo    = $line->transaction_date->copy()->addDays(2);

        if ($line->isCredit()) {
            $candidates = ArReceipt::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
                ->whereBetween('receipt_date', [$dateFrom, $dateTo])
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_id')->from('bank_statement_lines')
                        ->where('matched_type', ArReceipt::class)->whereNotNull('matched_id');
                })
                ->get();

            $matched = $candidates->filter(fn ($r) => $r->reference && str_contains($line->description, $r->reference));
            if ($matched->count() === 1) {
                $this->reconciliation->link($line, $matched->first(), $importer, 'medium');
                return true;
            }
            return false;
        }

        $candidates = ApPayment::where('org_bank_account_id', $stmt->org_bank_account_id)
            ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
            ->whereBetween('payment_date', [$dateFrom, $dateTo])
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ApPayment::class)->whereNotNull('matched_id');
            })
            ->get();

        $matched = $candidates->filter(fn ($p) => $p->reference && str_contains($line->description, $p->reference));
        if ($matched->count() === 1) {
            $this->reconciliation->link($line, $matched->first(), $importer, 'medium');
            return true;
        }

        return false;
    }

    private function tryTier3(BankStatementLine $line, BankStatement $stmt, $importer): string
    {
        $absAmount = abs((float) $line->amount);
        $dateFrom  = $line->transaction_date->copy()->subDays(2);
        $dateTo    = $line->transaction_date->copy()->addDays(2);

        if ($line->isCredit()) {
            $candidates = ArReceipt::where('org_bank_account_id', $stmt->org_bank_account_id)
                ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
                ->whereBetween('receipt_date', [$dateFrom, $dateTo])
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_id')->from('bank_statement_lines')
                        ->where('matched_type', ArReceipt::class)->whereNotNull('matched_id');
                })
                ->get();

            if ($candidates->count() === 1) {
                $this->reconciliation->link($line, $candidates->first(), $importer, 'low');
                return 'linked';
            }
            return $candidates->count() > 1 ? 'ambiguous' : 'none';
        }

        $candidates = ApPayment::where('org_bank_account_id', $stmt->org_bank_account_id)
            ->whereBetween('amount', [$absAmount - 0.005, $absAmount + 0.005])
            ->whereBetween('payment_date', [$dateFrom, $dateTo])
            ->whereNotIn('id', function ($q) {
                $q->select('matched_id')->from('bank_statement_lines')
                    ->where('matched_type', ApPayment::class)->whereNotNull('matched_id');
            })
            ->get();

        if ($candidates->count() === 1) {
            $this->reconciliation->link($line, $candidates->first(), $importer, 'low');
            return 'linked';
        }
        return $candidates->count() > 1 ? 'ambiguous' : 'none';
    }
}

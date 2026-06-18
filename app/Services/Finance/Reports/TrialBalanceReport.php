<?php

declare(strict_types=1);

namespace App\Services\Finance\Reports;

use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonInterface;

/**
 * Trial Balance: each account's natural balance placed in its debit or credit
 * column by type. Σ debit column = Σ credit column (the integrity proof) because
 * every posted entry is itself balanced.
 */
class TrialBalanceReport
{
    public function __construct(private readonly LedgerBalanceService $ledger)
    {
    }

    /** @return array{as_of:string, rows:array<int,array{code:string,name:string,type:string,debit:float,credit:float}>, total_debit:float, total_credit:float, balanced:bool} */
    public function forDate(CarbonInterface $date): array
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $rows = [];

        foreach ($this->ledger->asOf($date) as $b) {
            $isDebitSide = in_array($b->type, ['asset', 'expense'], true);
            $debit  = $isDebitSide ? $b->natural_balance : 0.0;
            $credit = $isDebitSide ? 0.0 : $b->natural_balance;

            $totalDebit  += $debit;
            $totalCredit += $credit;

            $rows[] = [
                'code'   => $b->code,
                'name'   => $b->name,
                'type'   => $b->type,
                'debit'  => round($debit, 2),
                'credit' => round($credit, 2),
            ];
        }

        $totalDebit  = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);

        return [
            'as_of'        => $date->toDateString(),
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced'     => abs($totalDebit - $totalCredit) < 0.005,
        ];
    }
}

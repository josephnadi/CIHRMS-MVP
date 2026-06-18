<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalEntryStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * On-the-fly ledger balances. Aggregates POSTED journal_lines by account over a
 * date window and applies the natural-balance sign by account type
 * (asset/expense = debit - credit; liability/equity/income = credit - debit).
 * Every financial statement is a presentation of this one method.
 */
class LedgerBalanceService
{
    /**
     * One row per account with posted activity in the window.
     *
     * @return Collection<int, object{account_id:int, code:string, name:string, type:string, debit_total:float, credit_total:float, natural_balance:float}>
     */
    public function balances(?CarbonInterface $from, CarbonInterface $to): Collection
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('gl_accounts as ga', 'ga.id', '=', 'jl.gl_account_id')
            ->where('je.status', JournalEntryStatus::Posted->value)
            ->whereDate('je.entry_date', '<=', $to->toDateString());

        if ($from !== null) {
            $query->whereDate('je.entry_date', '>=', $from->toDateString());
        }

        return $query
            ->groupBy('ga.id', 'ga.code', 'ga.name', 'ga.type')
            ->selectRaw('ga.id as account_id, ga.code, ga.name, ga.type, SUM(jl.debit_amount) as debit_total, SUM(jl.credit_amount) as credit_total')
            ->orderBy('ga.code')
            ->get()
            ->map(function ($r) {
                $debit  = (float) $r->debit_total;
                $credit = (float) $r->credit_total;

                return (object) [
                    'account_id'      => (int) $r->account_id,
                    'code'            => $r->code,
                    'name'            => $r->name,
                    'type'            => $r->type,
                    'debit_total'     => round($debit, 2),
                    'credit_total'    => round($credit, 2),
                    'natural_balance' => $this->naturalBalance($r->type, $debit, $credit),
                ];
            });
    }

    /** Cumulative balances at a point in time. */
    public function asOf(CarbonInterface $date): Collection
    {
        return $this->balances(null, $date);
    }

    /** Period activity (flow) within a date range, inclusive. */
    public function activity(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->balances($from, $to);
    }

    private function naturalBalance(string $type, float $debit, float $credit): float
    {
        return in_array($type, ['asset', 'expense'], true)
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }
}

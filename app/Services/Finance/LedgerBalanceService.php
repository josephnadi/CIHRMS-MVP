<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalEntryStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * On-the-fly ledger balances. Aggregates POSTED and REVERSED journal_lines by
 * account over a date window and applies the natural-balance sign by account type
 * (asset/expense = debit - credit; liability/equity/income = credit - debit).
 *
 * A reversal keeps the ORIGINAL entry with status Reversed and posts a separate
 * opposite-signed entry with status Posted; both must be counted so the pair nets
 * to zero (matching the gl_account_balances invariant). Only Draft is excluded.
 *
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
            ->whereIn('je.status', [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value])
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

    /**
     * Individual posted/reversed lines for one account in a window (GL drill-down).
     *
     * @return Collection<int, object{entry_id:int, reference:string, entry_date:string, narration:?string, debit:float, credit:float}>
     */
    public function accountLines(int $accountId, ?CarbonInterface $from, CarbonInterface $to): Collection
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.gl_account_id', $accountId)
            ->whereIn('je.status', [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value])
            ->whereDate('je.entry_date', '<=', $to->toDateString());

        if ($from !== null) {
            $query->whereDate('je.entry_date', '>=', $from->toDateString());
        }

        return $query
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jl.line_no')
            ->get(['je.id as entry_id', 'je.reference', 'je.entry_date', 'je.narration', 'jl.debit_amount', 'jl.credit_amount'])
            ->map(fn ($r) => (object) [
                'entry_id'   => (int) $r->entry_id,
                'reference'  => $r->reference,
                'entry_date' => (string) $r->entry_date,
                'narration'  => $r->narration,
                'debit'      => round((float) $r->debit_amount, 2),
                'credit'     => round((float) $r->credit_amount, 2),
            ]);
    }

    /**
     * The equal-length period immediately preceding [$from, $to] — used for
     * comparative statement columns.
     *
     * @return array{0:CarbonImmutable, 1:CarbonImmutable} [priorFrom, priorTo]
     */
    public function priorPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $f = CarbonImmutable::parse($from->toDateString());
        $t = CarbonImmutable::parse($to->toDateString());
        $lengthDays = $f->diffInDays($t);
        $priorTo   = $f->subDay();
        $priorFrom = $priorTo->subDays($lengthDays);

        return [$priorFrom, $priorTo];
    }

    private function naturalBalance(string $type, float $debit, float $credit): float
    {
        return in_array($type, ['asset', 'expense'], true)
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }
}

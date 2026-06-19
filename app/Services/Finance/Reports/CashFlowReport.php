<?php

declare(strict_types=1);

namespace App\Services\Finance\Reports;

use App\Enums\JournalEntryStatus;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Statement of Cash Flows, direct and indirect. Both reconcile, by construction,
 * to the actual net change in the cash accounts (org bank accounts + Cash on Hand
 * + Cash in Transit) for the period.
 */
class CashFlowReport
{
    public function __construct(private readonly LedgerBalanceService $ledger)
    {
    }

    /** @return array{from:string,to:string,net_change:float,direct:array,indirect:array} */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $netChange = $this->netChangeInCash($from, $to);

        return [
            'from'       => $from->toDateString(),
            'to'         => $to->toDateString(),
            'net_change' => $netChange,
            'direct'     => $this->direct($from, $to),
            'indirect'   => $this->indirect($from, $to),
        ];
    }

    /** GL account ids treated as cash: org bank accounts + Cash on Hand (1010) + Cash in Transit (1130). */
    private function cashAccountIds(): array
    {
        $bank  = OrgBankAccount::query()->whereNotNull('gl_account_id')->pluck('gl_account_id')->all();
        $extra = GlAccount::query()->whereIn('code', ['1010', '1130'])->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_merge($bank, $extra))));
    }

    private function statuses(): array
    {
        return [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value];
    }

    public function netChangeInCash(CarbonInterface $from, CarbonInterface $to): float
    {
        $net = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('jl.gl_account_id', $this->cashAccountIds())
            ->whereIn('je.status', $this->statuses())
            ->whereDate('je.entry_date', '>=', $from->toDateString())
            ->whereDate('je.entry_date', '<=', $to->toDateString())
            ->selectRaw('COALESCE(SUM(jl.debit_amount - jl.credit_amount), 0) as net')
            ->value('net');

        return round((float) $net, 2);
    }

    private function direct(CarbonInterface $from, CarbonInterface $to): array
    {
        $cashIds = $this->cashAccountIds();

        // Entries (in window, posted/reversed) that touch a cash account.
        $entryIds = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('jl.gl_account_id', $cashIds)
            ->whereIn('je.status', $this->statuses())
            ->whereDate('je.entry_date', '>=', $from->toDateString())
            ->whereDate('je.entry_date', '<=', $to->toDateString())
            ->distinct()
            ->pluck('jl.journal_entry_id');

        $operating = 0.0;
        $investing = 0.0;
        $financing = 0.0;

        if ($entryIds->isNotEmpty()) {
            $contra = DB::table('journal_lines as jl')
                ->join('gl_accounts as ga', 'ga.id', '=', 'jl.gl_account_id')
                ->whereIn('jl.journal_entry_id', $entryIds)
                ->whereNotIn('jl.gl_account_id', $cashIds)
                ->selectRaw('ga.type, ga.code, SUM(jl.debit_amount - jl.credit_amount) as net')
                ->groupBy('ga.type', 'ga.code')
                ->get();

            foreach ($contra as $row) {
                // Cash contribution = -(contra debit - credit).
                $cash = -(float) $row->net;
                $category = $this->classify($row->type, $row->code);
                $$category += $cash;
            }
        }

        $operating = round($operating, 2);
        $investing = round($investing, 2);
        $financing = round($financing, 2);

        return [
            'operating' => $operating,
            'investing' => $investing,
            'financing' => $financing,
            'net'       => round($operating + $investing + $financing, 2),
        ];
    }

    private function indirect(CarbonInterface $from, CarbonInterface $to): array
    {
        $act = $this->ledger->activity($from, $to)->keyBy('code');

        $sumType = fn (string $type) => (float) $act->filter(fn ($r) => $r->type === $type)->sum('natural_balance');
        $nat     = fn (string $code) => (float) ($act[$code]->natural_balance ?? 0.0);

        $surplus   = round($sumType('income') - $sumType('expense'), 2);
        $liabDelta = round($sumType('liability'), 2);
        $equityD   = round($sumType('equity'), 2);
        $arDelta   = round($nat('1200'), 2);
        $loansD    = round($nat('1300'), 2);

        $operating = round($surplus + $liabDelta - $arDelta, 2);
        $investing = round(-$loansD, 2);
        $financing = round($equityD, 2);

        return [
            'surplus'        => $surplus,
            'liability_change' => $liabDelta,
            'ar_change'      => $arDelta,
            'loans_change'   => $loansD,
            'equity_change'  => $equityD,
            'operating'      => $operating,
            'investing'      => $investing,
            'financing'      => $financing,
            'net'            => round($operating + $investing + $financing, 2),
        ];
    }

    /** Category for a contra account: equity → financing; Loans Receivable (1300) → investing; else operating. */
    private function classify(string $type, string $code): string
    {
        if ($type === 'equity') {
            return 'financing';
        }
        if ($code === '1300') {
            return 'investing';
        }

        return 'operating';
    }
}

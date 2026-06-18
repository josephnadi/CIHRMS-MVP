<?php

declare(strict_types=1);

namespace App\Services\Finance\Reports;

use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Statement of Financial Activities (Income & Expenditure). Income (4xxx) less
 * expenditure (5xxx) for the period → Surplus/Deficit, with the immediately
 * preceding equal-length period as a comparative.
 */
class IncomeExpenditureReport
{
    public function __construct(private readonly LedgerBalanceService $ledger)
    {
    }

    /** @return array{from:string, to:string, income:array, expenditure:array, surplus_current:float, surplus_prior:float} */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $current = $this->ledger->activity($from, $to)->keyBy('code');
        [$priorFrom, $priorTo] = $this->ledger->priorPeriod($from, $to);
        $prior = $this->ledger->activity($priorFrom, $priorTo)->keyBy('code');

        $income      = $this->section($current, $prior, 'income');
        $expenditure = $this->section($current, $prior, 'expense');

        return [
            'from'            => $from->toDateString(),
            'to'              => $to->toDateString(),
            'income'          => $income,
            'expenditure'     => $expenditure,
            'surplus_current' => round($income['total_current'] - $expenditure['total_current'], 2),
            'surplus_prior'   => round($income['total_prior'] - $expenditure['total_prior'], 2),
        ];
    }

    /** Build a section (income or expense) with current + prior amounts per account. */
    private function section(Collection $current, Collection $prior, string $type): array
    {
        $codes = $current->union($prior)->filter(fn ($r) => $r->type === $type)->keys()->unique()->sort()->values();

        $rows = [];
        $totalCurrent = 0.0;
        $totalPrior = 0.0;

        foreach ($codes as $code) {
            $cur = (float) ($current[$code]->natural_balance ?? 0.0);
            $pri = (float) ($prior[$code]->natural_balance ?? 0.0);
            $name = $current[$code]->name ?? $prior[$code]->name ?? $code;
            $totalCurrent += $cur;
            $totalPrior += $pri;

            $rows[] = ['code' => $code, 'name' => $name, 'current' => round($cur, 2), 'prior' => round($pri, 2)];
        }

        return ['rows' => $rows, 'total_current' => round($totalCurrent, 2), 'total_prior' => round($totalPrior, 2)];
    }
}

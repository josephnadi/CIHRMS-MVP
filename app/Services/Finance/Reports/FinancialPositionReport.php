<?php

declare(strict_types=1);

namespace App\Services\Finance\Reports;

use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Statement of Financial Position (Balance Sheet). Assets (1xxx), Liabilities
 * (2xxx), Equity/Funds (3xxx) as-of a date, with the cumulative surplus
 * (income − expenditure to date) folded into funds. The accounting equation
 * Assets = Liabilities + Equity + Surplus holds for any balanced ledger.
 * Comparative column is the same date one year prior.
 */
class FinancialPositionReport
{
    public function __construct(private readonly LedgerBalanceService $ledger)
    {
    }

    /** @return array */
    public function asOf(CarbonInterface $date): array
    {
        $priorDate = CarbonImmutable::parse($date->toDateString())->subYear();

        $current = $this->ledger->asOf($date)->keyBy('code');
        $prior   = $this->ledger->asOf($priorDate)->keyBy('code');

        $assets      = $this->section($current, $prior, 'asset');
        $liabilities = $this->section($current, $prior, 'liability');
        $equity      = $this->section($current, $prior, 'equity');

        $surplusCurrent = $this->surplus($current);
        $surplusPrior   = $this->surplus($prior);

        $fundsCurrent = round($equity['total_current'] + $surplusCurrent, 2);
        $fundsPrior   = round($equity['total_prior'] + $surplusPrior, 2);

        return [
            'as_of'               => $date->toDateString(),
            'comparative_as_of'   => $priorDate->toDateString(),
            'assets'              => $assets,
            'liabilities'         => $liabilities,
            'equity'              => $equity,
            'surplus_current'     => $surplusCurrent,
            'surplus_prior'       => $surplusPrior,
            'total_funds_current' => $fundsCurrent,
            'total_funds_prior'   => $fundsPrior,
            'balanced_current'    => abs($assets['total_current'] - ($liabilities['total_current'] + $fundsCurrent)) < 0.005,
            'balanced_prior'      => abs($assets['total_prior'] - ($liabilities['total_prior'] + $fundsPrior)) < 0.005,
        ];
    }

    private function surplus(Collection $balances): float
    {
        $income  = $balances->filter(fn ($r) => $r->type === 'income')->sum('natural_balance');
        $expense = $balances->filter(fn ($r) => $r->type === 'expense')->sum('natural_balance');

        return round((float) $income - (float) $expense, 2);
    }

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

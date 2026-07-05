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

    /**
     * CIHRM Income & Expenditure:
     *   Operating Income − Expenditure = Net Operating Income
     *   Net Operating Income + Other Income = Surplus / (Deficit)
     * `income` (combined) and the surplus fields are retained for back-compat.
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $current = $this->ledger->activity($from, $to)->keyBy('code');
        [$priorFrom, $priorTo] = $this->ledger->priorPeriod($from, $to);
        $prior = $this->ledger->activity($priorFrom, $priorTo)->keyBy('code');

        // Operating = income not flagged 'other'; Other = flagged 'other'.
        $operating   = $this->section($current, $prior, 'income', fn ($r) => $r->statement_section !== 'other');
        $other       = $this->section($current, $prior, 'income', fn ($r) => $r->statement_section === 'other');
        $income       = $this->section($current, $prior, 'income');
        $expenditure = $this->section($current, $prior, 'expense');

        $netOpCurrent = round($operating['total_current'] - $expenditure['total_current'], 2);
        $netOpPrior   = round($operating['total_prior']   - $expenditure['total_prior'], 2);

        return [
            'from'                  => $from->toDateString(),
            'to'                    => $to->toDateString(),
            'operating_income'      => $operating,
            'expenditure'           => $expenditure,
            'net_operating_current' => $netOpCurrent,
            'net_operating_prior'   => $netOpPrior,
            'other_income'          => $other,
            'income'                => $income, // combined (back-compat)
            'surplus_current'       => round($netOpCurrent + $other['total_current'], 2),
            'surplus_prior'         => round($netOpPrior + $other['total_prior'], 2),
        ];
    }

    /** Build a section (income or expense) with current + prior amounts per account. */
    private function section(Collection $current, Collection $prior, string $type, ?callable $filter = null): array
    {
        $match = fn ($r) => $r->type === $type && ($filter === null || $filter($r));
        $codes = $current->union($prior)->filter($match)->keys()->unique()->sort()->values();

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

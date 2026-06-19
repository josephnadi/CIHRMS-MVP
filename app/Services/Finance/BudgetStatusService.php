<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\GlAccount;
use App\Services\Finance\Reports\BudgetVsActualsReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Advisory (never-blocking) budget controls. Nothing here is wired into the
 * posting path — these are read-only queries a form or dashboard may consult
 * to show budget headroom and breaches.
 */
class BudgetStatusService
{
    public function __construct(
        private readonly LedgerBalanceService $ledger,
        private readonly BudgetVsActualsReport $report,
    ) {
    }

    /**
     * Approved annual budget for the account, less actuals from the start of the
     * as-of year through the as-of date. Positive = headroom; negative = over budget.
     * A draft or absent budget counts as zero annual budget. Purely advisory.
     */
    public function remaining(GlAccount $account, CarbonInterface $asOf): float
    {
        $year = (int) $asOf->year;

        $budget = Budget::whereHas('fiscalYear', fn ($q) => $q->where('year', $year))
            ->where('status', BudgetStatus::Approved->value)
            ->with('lines')->first();

        $annual = 0.0;
        if ($budget !== null) {
            $line = $budget->lines->firstWhere('gl_account_id', $account->id);
            $annual = $line !== null ? (float) $line->annual_amount : 0.0;
        }

        $yearStart = CarbonImmutable::create($year, 1, 1);
        $row = $this->ledger->activity($yearStart, $asOf)->firstWhere('code', $account->code);
        $actual = $row !== null ? (float) $row->natural_balance : 0.0;

        return round($annual - $actual, 2);
    }

    /**
     * Over-budget (unfavourable) accounts from the budget-vs-actuals report,
     * most-negative variance first.
     *
     * @return array<int, array{code:string,name:string,type:string,ytd_budget:float,ytd_actual:float,variance:float}>
     */
    public function overBudgetAlerts(int $year, int $asOfPeriodNo = 12): array
    {
        $report = $this->report->forYear($year, $asOfPeriodNo);

        $alerts = [];
        foreach ($report['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['favourable'] === false) {
                    $alerts[] = [
                        'code'       => $row['code'],
                        'name'       => $row['name'],
                        'type'       => $row['type'],
                        'ytd_budget' => $row['ytd_budget'],
                        'ytd_actual' => $row['ytd_actual'],
                        'variance'   => $row['variance'],
                    ];
                }
            }
        }

        usort($alerts, fn ($a, $b) => $a['variance'] <=> $b['variance']); // most negative first

        return $alerts;
    }
}

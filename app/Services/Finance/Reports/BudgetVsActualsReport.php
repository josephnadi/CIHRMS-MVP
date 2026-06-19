<?php

declare(strict_types=1);

namespace App\Services\Finance\Reports;

use App\Models\Budget;
use App\Models\GlAccount;
use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonImmutable;

/**
 * Budget vs Actuals for a fiscal year. Reads the year's approved/draft budget
 * lines and the ledger's YTD activity, derives an even monthly spread
 * (annual / 12 × periodNo), and reports the variance with a type-aware
 * favourable flag. Read-only — never creates a budget or mutates the ledger.
 */
class BudgetVsActualsReport
{
    /** Canonical statement order; only non-empty types are emitted. */
    private const TYPE_ORDER = ['income', 'expense', 'asset', 'liability', 'equity'];

    public function __construct(private readonly LedgerBalanceService $ledger)
    {
    }

    public function forYear(int $year, int $asOfPeriodNo = 12): array
    {
        $asOfPeriodNo = max(1, min(12, $asOfPeriodNo));
        $yearStart = CarbonImmutable::create($year, 1, 1);
        $asOf      = CarbonImmutable::create($year, $asOfPeriodNo, 1)->endOfMonth();

        // Budget (read-only lookup; never created here).
        $budget = Budget::whereHas('fiscalYear', fn ($q) => $q->where('year', $year))
            ->with('lines')->first();

        $budgetByCode = [];
        if ($budget !== null) {
            $accounts = GlAccount::whereIn('id', $budget->lines->pluck('gl_account_id'))
                ->get(['id', 'code', 'name', 'type'])->keyBy('id');
            foreach ($budget->lines as $line) {
                $acc = $accounts[$line->gl_account_id] ?? null;
                if ($acc === null) {
                    continue;
                }
                $budgetByCode[$acc->code] = [
                    'annual' => (float) $line->annual_amount,
                    'name'   => $acc->name,
                    'type'   => $acc->type->value,
                ];
            }
        }

        $actuals = $this->ledger->activity($yearStart, $asOf)->keyBy('code');

        $codes = collect(array_keys($budgetByCode))->map(fn ($c) => (string) $c)
            ->merge($actuals->keys())
            ->unique()->sort()->values();

        $byType = [];
        foreach ($codes as $code) {
            $annual    = $budgetByCode[$code]['annual'] ?? 0.0;
            $ytdBudget = round($annual / 12 * $asOfPeriodNo, 2);
            $ytdActual = round((float) ($actuals[$code]->natural_balance ?? 0.0), 2);
            $type      = $actuals[$code]->type ?? ($budgetByCode[$code]['type'] ?? '');
            $name      = $actuals[$code]->name ?? ($budgetByCode[$code]['name'] ?? $code);
            $variance  = round($ytdBudget - $ytdActual, 2);

            $byType[$type][] = [
                'code'          => $code,
                'name'          => $name,
                'type'          => $type,
                'annual_budget' => round($annual, 2),
                'ytd_budget'    => $ytdBudget,
                'ytd_actual'    => $ytdActual,
                'variance'      => $variance,
                'favourable'    => $this->favourable($type, $ytdActual, $ytdBudget),
            ];
        }

        $groups = [];
        $totals = ['annual_budget' => 0.0, 'ytd_budget' => 0.0, 'ytd_actual' => 0.0, 'variance' => 0.0];

        foreach (self::TYPE_ORDER as $type) {
            if (empty($byType[$type])) {
                continue;
            }
            $rows = $byType[$type];
            $group = [
                'type'          => $type,
                'rows'          => $rows,
                'annual_budget' => round(array_sum(array_column($rows, 'annual_budget')), 2),
                'ytd_budget'    => round(array_sum(array_column($rows, 'ytd_budget')), 2),
                'ytd_actual'    => round(array_sum(array_column($rows, 'ytd_actual')), 2),
                'variance'      => round(array_sum(array_column($rows, 'variance')), 2),
            ];
            $groups[] = $group;
            $totals['annual_budget'] = round($totals['annual_budget'] + $group['annual_budget'], 2);
            $totals['ytd_budget']    = round($totals['ytd_budget'] + $group['ytd_budget'], 2);
            $totals['ytd_actual']    = round($totals['ytd_actual'] + $group['ytd_actual'], 2);
            $totals['variance']      = round($totals['variance'] + $group['variance'], 2);
        }

        return [
            'year'         => $year,
            'as_of_period' => $asOfPeriodNo,
            'as_of'        => $asOf->toDateString(),
            'has_budget'   => $budget !== null,
            'groups'       => $groups,
            'totals'       => $totals,
        ];
    }

    /** Expense: under budget is favourable. Income: at/over target is favourable. Others: informational. */
    private function favourable(string $type, float $actual, float $budget): ?bool
    {
        return match ($type) {
            'expense' => $actual <= $budget,
            'income'  => $actual >= $budget,
            default   => null,
        };
    }
}

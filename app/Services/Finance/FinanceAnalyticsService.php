<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\ArInvoice;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\PayrollRun;
use App\Models\VendorInvoice;
use App\Services\Finance\Reports\BudgetVsActualsReport;
use App\Services\Finance\Reports\IncomeExpenditureReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read-only aggregation for the finance analytics dashboard: point-in-time KPIs
 * and monthly trend series, built from the existing ledger/report services and
 * the AP/AR subledgers. Never mutates anything.
 */
class FinanceAnalyticsService
{
    public function __construct(
        private readonly LedgerBalanceService $ledger,
        private readonly IncomeExpenditureReport $incomeExpenditure,
        private readonly BudgetVsActualsReport $budget,
    ) {
    }

    /** @return array<string, float> */
    public function kpis(CarbonInterface $from, CarbonInterface $to): array
    {
        $ie = $this->incomeExpenditure->forPeriod($this->imm($from), $this->imm($to));
        $income      = (float) ($ie['income']['total_current'] ?? 0);
        $expenditure = (float) ($ie['expenditure']['total_current'] ?? 0);

        return [
            'cash_position'       => $this->cashPositionAsOf($to),
            'income_ytd'          => round($income, 2),
            'expenditure_ytd'     => round($expenditure, 2),
            'surplus_ytd'         => round($income - $expenditure, 2),
            'ap_outstanding'      => $this->apOutstanding(),
            'ar_outstanding'      => $this->arOutstanding(),
            'budget_variance'     => round((float) ($this->budget->forYear((int) $this->imm($to)->year)['totals']['variance'] ?? 0), 2),
            'latest_payroll_cost' => $this->latestPayrollCost(),
        ];
    }

    public function trends(CarbonInterface $from, CarbonInterface $to): array
    {
        $months = $this->monthRange($this->imm($from), $this->imm($to));

        $income = $expenditure = $surplus = $cash = [];
        foreach ($months as $ym) {
            $start = CarbonImmutable::parse($ym . '-01')->startOfMonth();
            $end   = $start->endOfMonth();
            $act   = $this->ledger->activity($start, $end);

            $inc = round((float) $act->where('type', 'income')->sum('natural_balance'), 2);
            $exp = round((float) $act->where('type', 'expense')->sum('natural_balance'), 2);

            $income[]      = $inc;
            $expenditure[] = $exp;
            $surplus[]     = round($inc - $exp, 2);
            $cash[]        = $this->cashPositionAsOf($end);
        }

        return [
            'months'       => $months,
            'income'       => $income,
            'expenditure'  => $expenditure,
            'surplus'      => $surplus,
            'cash'         => $cash,
            'top_expenses' => $this->topExpenses($this->imm($from), $this->imm($to), 8),
            'aging'        => $this->aging(),
            'budget'       => $this->budgetByType((int) $this->imm($to)->year),
        ];
    }

    /** @return array{ar: array<string,float>, ap: array<string,float>} */
    public function aging(): array
    {
        return [
            'ar' => $this->agingFor(ArInvoice::query()->whereIn('status', ['approved', 'partially_paid']), 'amount_received'),
            'ap' => $this->agingFor(VendorInvoice::query()->whereIn('status', ['approved', 'partially_paid']), 'amount_paid'),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function cashPositionAsOf(CarbonInterface $date): float
    {
        $ids = $this->cashAccountIds();
        if (empty($ids)) {
            return 0.0;
        }

        return round((float) $this->ledger->asOf($date)
            ->whereIn('account_id', $ids)
            ->sum('natural_balance'), 2);
    }

    /** GL ids treated as cash: active org bank accounts + Cash on Hand (1010) + Cash in Transit (1130). */
    private function cashAccountIds(): array
    {
        $bank  = OrgBankAccount::query()->whereNotNull('gl_account_id')->pluck('gl_account_id')->all();
        $extra = GlAccount::query()->whereIn('code', ['1010', '1130'])->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_merge($bank, $extra))));
    }

    private function apOutstanding(): float
    {
        return round((float) VendorInvoice::query()->whereIn('status', ['approved', 'partially_paid'])
            ->get()->sum(fn ($i) => (float) $i->total - (float) $i->amount_paid), 2);
    }

    private function arOutstanding(): float
    {
        return round((float) ArInvoice::query()->whereIn('status', ['approved', 'partially_paid'])
            ->get()->sum(fn ($i) => (float) $i->total - (float) $i->amount_received), 2);
    }

    private function latestPayrollCost(): float
    {
        $run = PayrollRun::query()->whereIn('status', ['approved', 'paid'])
            ->orderByDesc('period_year')->orderByDesc('period_month')->first();

        return round((float) ($run->gross_total ?? 0), 2);
    }

    /** @return array<int, array{code:string, name:string, amount:float}> */
    private function topExpenses(CarbonInterface $from, CarbonInterface $to, int $limit): array
    {
        return $this->ledger->activity($from, $to)
            ->where('type', 'expense')
            ->sortByDesc('natural_balance')
            ->take($limit)
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'amount' => round((float) $r->natural_balance, 2)])
            ->values()->all();
    }

    /** @return array<int, array{type:string, ytd_budget:float, ytd_actual:float, variance:float}> */
    private function budgetByType(int $year): array
    {
        $report = $this->budget->forYear($year);

        return collect($report['groups'] ?? [])->map(fn ($g) => [
            'type'       => (string) $g['type'],
            'ytd_budget' => round((float) $g['ytd_budget'], 2),
            'ytd_actual' => round((float) $g['ytd_actual'], 2),
            'variance'   => round((float) $g['variance'], 2),
        ])->values()->all();
    }

    /** Bucket open invoices by days-overdue (current / 1-30 / 31-60 / 61+). */
    private function agingFor(\Illuminate\Database\Eloquent\Builder $q, string $paidColumn): array
    {
        $buckets = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0];
        $today = CarbonImmutable::today();

        foreach ($q->get() as $inv) {
            $outstanding = (float) $inv->total - (float) $inv->{$paidColumn};
            if ($outstanding <= 0) {
                continue;
            }
            $due = $inv->due_date ? CarbonImmutable::parse($inv->due_date) : $today;
            $daysOverdue = $due->lessThan($today) ? $due->diffInDays($today) : 0;

            $key = match (true) {
                $daysOverdue <= 0  => 'current',
                $daysOverdue <= 30 => 'd30',
                $daysOverdue <= 60 => 'd60',
                default            => 'd90',
            };
            $buckets[$key] = round($buckets[$key] + $outstanding, 2);
        }

        return $buckets;
    }

    /** @return string[] 'YYYY-MM' inclusive */
    private function monthRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();
        $last   = $to->startOfMonth();
        while ($cursor->lessThanOrEqualTo($last)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function imm(CarbonInterface $d): CarbonImmutable
    {
        return CarbonImmutable::parse($d->toDateString());
    }
}

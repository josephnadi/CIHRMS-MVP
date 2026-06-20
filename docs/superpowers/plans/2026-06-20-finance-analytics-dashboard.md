# Finance Analytics Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A finance analytics dashboard — KPIs, trend charts (Chart.js), fiscal-year/date filtering, CSV+PDF+PNG export — gated by a dedicated `finance.analytics.view` permission (super_admin/ceo via wildcard).

**Architecture:** `FinanceAnalyticsService` aggregates existing finance services into KPIs + monthly trends; `Finance/AnalyticsController` renders the Inertia page + CSV/PDF exports; `Finance/Analytics/Dashboard.vue` shows KPI cards + Chart.js charts + filters. Additive — no existing behaviour changes.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Chart.js + vue-chartjs, Pest.

## Global Constraints

- **Read-only & additive**: never mutates the ledger; new service/controller/page/permission/dependency only; existing suites stay green.
- RBAC: every route + the nav entry gated by `finance.analytics.view`; super_admin/ceo pass via the `*` wildcard.
- Export routes use the `export.csv`/`export.pdf` suffix (AuthenticatedRoutesSmokeTest skip); every filter input carries `aria-label`.
- `declare(strict_types=1)`; `DbExpr` for any date grouping; reuse `LedgerBalanceService`/`IncomeExpenditureReport`/`BudgetVsActualsReport`.

**Spec:** `docs/superpowers/specs/2026-06-20-finance-analytics-dashboard-design.md`

---

### Task 1: Permission + FinanceAnalyticsService

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Create: `app/Services/Finance/FinanceAnalyticsService.php`
- Test: `tests/Feature/Finance/FinanceAnalyticsServiceTest.php`

**Interfaces:**
- Produces: permission `finance.analytics.view` (→ finance_officer + auditor); `FinanceAnalyticsService::kpis(CarbonInterface, CarbonInterface): array`, `trends(CarbonInterface, CarbonInterface): array`, `aging(): array`.

- [ ] **Step 1: Add the permission**

In `database/seeders/RolePermissionSeeder.php`: add to `PERMISSIONS` (after `finance.reports.view`):

```php
        'finance.analytics.view' => ['Finance', 'View the finance analytics dashboard (KPIs, charts)'],
```

Grant `'finance.analytics.view'` in the SAME role arrays that hold `'finance.reports.view'` — both `finance_officer` and `auditor` (add the line next to each `'finance.reports.view',`).

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\ArInvoice;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\FinanceAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    $this->svc = app(FinanceAnalyticsService::class);

    // One posted JE: DR Bank 1100 5000 / CR Membership Dues 4100 5000 (income), in March.
    $je = JournalEntry::create([
        'reference' => 'JE-AN-1', 'entry_date' => '2026-03-10', 'narration' => 'income',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 5000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '4100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 5000]);

    // An expense JE: DR Operations 5200 2000 / CR Bank 1100 2000, in April.
    $je2 = JournalEntry::create([
        'reference' => 'JE-AN-2', 'entry_date' => '2026-04-12', 'narration' => 'expense',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je2->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '5200')->value('id'), 'debit_amount' => 2000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je2->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 2000]);
});

it('computes KPIs over the year-to-date window', function () {
    $from = CarbonImmutable::create(2026, 1, 1);
    $to   = CarbonImmutable::create(2026, 12, 31);
    $k = $this->svc->kpis($from, $to);

    expect($k['income_ytd'])->toEqualWithDelta(5000.0, 0.01)
        ->and($k['expenditure_ytd'])->toEqualWithDelta(2000.0, 0.01)
        ->and($k['surplus_ytd'])->toEqualWithDelta(3000.0, 0.01)
        ->and($k['cash_position'])->toEqualWithDelta(3000.0, 0.01); // 1100: +5000 -2000
});

it('builds monthly trend series across the range', function () {
    $from = CarbonImmutable::create(2026, 1, 1);
    $to   = CarbonImmutable::create(2026, 6, 30);
    $t = $this->svc->trends($from, $to);

    expect($t['months'])->toHaveCount(6)
        ->and($t['months'][0])->toBe('2026-01')
        ->and($t['income'][2])->toEqualWithDelta(5000.0, 0.01)      // March income
        ->and($t['expenditure'][3])->toEqualWithDelta(2000.0, 0.01) // April expense
        ->and($t['surplus'][2])->toEqualWithDelta(5000.0, 0.01)
        ->and($t['cash'][2])->toEqualWithDelta(5000.0, 0.01)        // cash asOf end of March = +5000
        ->and($t['cash'][3])->toEqualWithDelta(3000.0, 0.01);       // asOf end of April = +5000 -2000
    expect(collect($t['top_expenses'])->firstWhere('code', '5200')['amount'])->toEqualWithDelta(2000.0, 0.01);
});

it('reports AR outstanding and aging', function () {
    ArInvoice::create([
        'reference' => 'ARI-1', 'customer_id' => null, 'status' => 'approved',
        'invoice_date' => '2026-03-01', 'due_date' => now()->subDays(45)->toDateString(),
        'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000, 'amount_received' => 0,
    ]);
    $k = $this->svc->kpis(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::now());
    expect($k['ar_outstanding'])->toEqualWithDelta(1000.0, 0.01);

    $aging = $this->svc->aging();
    expect($aging['ar']['d30'] + $aging['ar']['d60'])->toBeGreaterThan(0.0); // 45 days overdue lands in a bucket
});
```

> If `ArInvoice::create` requires a real `customer_id`, create a `Customer` first (or set the column the factory/migration needs). Adjust minimally.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/FinanceAnalyticsServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 4: Write the service**

`app/Services/Finance/FinanceAnalyticsService.php`:

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/FinanceAnalyticsServiceTest.php`
Expected: PASS (all three).

- [ ] **Step 6: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php app/Services/Finance/FinanceAnalyticsService.php tests/Feature/Finance/FinanceAnalyticsServiceTest.php
git commit -m "feat(finance): FinanceAnalyticsService (KPIs + monthly trends + aging) + finance.analytics.view permission"
```

---

### Task 2: Controller + routes + CSV/PDF export

**Files:**
- Create: `app/Http/Controllers/Finance/AnalyticsController.php`
- Create: `resources/views/finance/analytics-pdf.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/FinanceAnalyticsEndpointTest.php`

**Interfaces:**
- Consumes: `FinanceAnalyticsService` (Task 1).
- Produces: `GET finance/analytics` (`finance.analytics`), `GET finance/analytics/export.csv` (`finance.analytics.csv`), `GET finance/analytics/export.pdf` (`finance.analytics.pdf`) — all `permission:finance.analytics.view`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
});

it('renders the analytics dashboard for finance_officer, auditor, and super_admin', function () {
    foreach (['finance_officer', 'auditor', 'super_admin'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/finance/analytics?year=2026')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Finance/Analytics/Dashboard')->has('kpis')->has('trends'));
    }
});

it('forbids an employee', function () {
    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/finance/analytics')->assertForbidden();
});

it('exports analytics CSV and PDF', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $csv = $this->actingAs($u)->get('/finance/analytics/export.csv?year=2026&from=2026-01-01&to=2026-12-31');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $pdf = $this->actingAs($u)->get('/finance/analytics/export.pdf?year=2026');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/FinanceAnalyticsEndpointTest.php`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Finance/AnalyticsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(private readonly FinanceAnalyticsService $analytics)
    {
    }

    public function dashboard(Request $request): Response
    {
        [$year, $from, $to] = $this->range($request);

        return Inertia::render('Finance/Analytics/Dashboard', [
            'activeModule' => 'finance-analytics',
            'year'         => $year,
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'kpis'         => $this->analytics->kpis($from, $to),
            'trends'       => $this->analytics->trends($from, $to),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        [, $from, $to] = $this->range($request);
        $t = $this->analytics->trends($from, $to);

        return response()->streamDownload(function () use ($t) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Month', 'Income', 'Expenditure', 'Surplus', 'Cash']);
            foreach ($t['months'] as $i => $m) {
                fputcsv($out, [$m, $t['income'][$i], $t['expenditure'][$i], $t['surplus'][$i], $t['cash'][$i]]);
            }
            fclose($out);
        }, "finance-analytics-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request): HttpResponse
    {
        [$year, $from, $to] = $this->range($request);

        return Pdf::loadView('finance.analytics-pdf', [
            'year'   => $year,
            'from'   => $from->toDateString(),
            'to'     => $to->toDateString(),
            'kpis'   => $this->analytics->kpis($from, $to),
            'trends' => $this->analytics->trends($from, $to),
        ])->download("finance-analytics-{$year}.pdf");
    }

    /** @return array{0:int,1:CarbonImmutable,2:CarbonImmutable} */
    private function range(Request $request): array
    {
        $year = (int) ($request->query('year') ?: CarbonImmutable::today()->year);
        $to   = $this->parse($request->query('to')) ?? CarbonImmutable::today();
        $from = $this->parse($request->query('from')) ?? CarbonImmutable::create($year, 1, 1);

        return [$year, $from, $to];
    }

    private function parse(?string $raw): ?CarbonImmutable
    {
        if (! $raw) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Write the PDF blade**

`resources/views/finance/analytics-pdf.blade.php` — a data snapshot (KPI table + monthly series table). Mirror `resources/views/finance/reports/trial-balance-pdf.blade.php` styling:

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 16px; margin: 0 0 2px; } .sub { color: #666; margin: 0 0 12px; }
h2 { font-size: 12px; margin: 12px 0 4px; }
table { width: 100%; border-collapse: collapse; } th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
th { text-align: left; background: #f3f3f3; } .r { text-align: right; }
</style></head>
<body>
    <h1>Finance Analytics</h1>
    <p class="sub">FY {{ $year }} — {{ $from }} to {{ $to }}</p>

    <h2>Key indicators</h2>
    <table>
        <tbody>
        @foreach ($kpis as $label => $value)
            <tr><td>{{ ucwords(str_replace('_', ' ', $label)) }}</td><td class="r">{{ number_format((float) $value, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Monthly trend</h2>
    <table>
        <thead><tr><th>Month</th><th class="r">Income</th><th class="r">Expenditure</th><th class="r">Surplus</th><th class="r">Cash</th></tr></thead>
        <tbody>
        @foreach ($trends['months'] as $i => $m)
            <tr><td>{{ $m }}</td>
                <td class="r">{{ number_format($trends['income'][$i], 2) }}</td>
                <td class="r">{{ number_format($trends['expenditure'][$i], 2) }}</td>
                <td class="r">{{ number_format($trends['surplus'][$i], 2) }}</td>
                <td class="r">{{ number_format($trends['cash'][$i], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, inside the `finance` group, add a block gated by `finance.analytics.view`:

```php
        Route::middleware('permission:finance.analytics.view')->group(function () {
            Route::get('analytics',             [\App\Http\Controllers\Finance\AnalyticsController::class, 'dashboard'])->name('analytics');
            Route::get('analytics/export.csv',  [\App\Http\Controllers\Finance\AnalyticsController::class, 'exportCsv'])->name('analytics.csv');
            Route::get('analytics/export.pdf',  [\App\Http\Controllers\Finance\AnalyticsController::class, 'exportPdf'])->name('analytics.pdf');
        });
```

- [ ] **Step 6: Run test + commit**

Run: `php artisan test tests/Feature/Finance/FinanceAnalyticsEndpointTest.php`
Expected: PASS (all three).

```bash
git add app/Http/Controllers/Finance/AnalyticsController.php resources/views/finance/analytics-pdf.blade.php routes/web.php tests/Feature/Finance/FinanceAnalyticsEndpointTest.php
git commit -m "feat(finance): analytics controller + routes + CSV/PDF export (finance.analytics.view)"
```

---

### Task 3: Chart.js dependency + wrapper components

**Files:**
- Modify: `package.json` (add `chart.js`, `vue-chartjs`)
- Create: `resources/js/Components/charts/ChartJs/LineChart.vue`, `BarChart.vue`, `DoughnutChart.vue`
- Test: build only.

- [ ] **Step 1: Install the dependency**

Run: `npm install chart.js vue-chartjs`
Expected: adds both to `package.json` dependencies + updates `package-lock.json`.

- [ ] **Step 2: Write thin wrappers over vue-chartjs**

Each wrapper registers the needed Chart.js pieces and exposes the chart instance for PNG export. Example `resources/js/Components/charts/ChartJs/LineChart.vue`:

```vue
<script setup>
import { Line } from 'vue-chartjs';
import {
    Chart as ChartJS, Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale, Filler,
} from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale, Filler);

defineProps({
    data:    { type: Object, required: true },
    options: { type: Object, default: () => ({ responsive: true, maintainAspectRatio: false }) },
});
</script>

<template>
    <Line :data="data" :options="options" />
</template>
```

`BarChart.vue` (register `BarElement` + scales, render `<Bar>`) and `DoughnutChart.vue` (register `ArcElement` + Tooltip/Legend, render `<Doughnut>`) follow the same shape. Keep them minimal — the page supplies `data`/`options`.

- [ ] **Step 3: Build to verify**

Run: `npm run build`
Expected: succeeds, no resolution/compile errors (chart.js + vue-chartjs resolve).

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json resources/js/Components/charts/ChartJs/LineChart.vue resources/js/Components/charts/ChartJs/BarChart.vue resources/js/Components/charts/ChartJs/DoughnutChart.vue
git commit -m "feat(finance): add Chart.js + vue-chartjs with Line/Bar/Doughnut wrappers"
```

---

### Task 4: Dashboard page + nav + regression gate

**Files:**
- Create: `resources/js/Pages/Finance/Analytics/Dashboard.vue`
- Modify: `resources/js/Pages/Finance/Hub.vue` (link) + the nav (`resources/js/Layouts/AuthenticatedLayout.vue`)
- Test: none new (verification only).

- [ ] **Step 1: Build the dashboard page**

`resources/js/Pages/Finance/Analytics/Dashboard.vue` — props `year:Number, from:String, to:String, kpis:Object, trends:Object`. Compose:
- **Filter bar**: a fiscal-year number input (`aria-label="Fiscal year"`), from/to date inputs (`aria-label="From date"`/`"To date"`), an Apply button that `router.get(route('finance.analytics'), { year, from, to }, { preserveState:false })`, and CSV/PDF download links (`route('finance.analytics.csv', {year, from, to})` / `.pdf`).
- **KPI strip**: cards for cash_position, income_ytd, expenditure_ytd, surplus_ytd, ap_outstanding, ar_outstanding, budget_variance, latest_payroll_cost — money-formatted (`Intl.NumberFormat('en-GH', { minimumFractionDigits: 2 })`), surplus/variance coloured by sign. Reuse the existing `ChartCard.vue` for framing where useful.
- **Charts** (use the Task 3 wrappers, each inside a `ChartCard`; give each chart `<div style="height:280px">` so `maintainAspectRatio:false` sizes correctly):
  1. Income vs Expenditure — `BarChart`, labels `trends.months`, two datasets (income, expenditure).
  2. Surplus — `LineChart`, `trends.surplus`.
  3. Cash balance — `LineChart`, `trends.cash`.
  4. AR & AP aging — `DoughnutChart` (AR) + small legend; or a `BarChart` with current/30/60/90+ for AR & AP.
  5. Budget vs Actuals — `BarChart` from `trends.budget` (label by type, datasets ytd_budget vs ytd_actual).
  6. Top expenses — `BarChart` horizontal (`indexAxis:'y'`) from `trends.top_expenses`.
- **Per-chart PNG**: a small "PNG" button on each `ChartCard` that grabs the chart instance and downloads `chart.toBase64Image()` (use a `ref` on the wrapper exposing the underlying chart, or vue-chartjs's `chartRef`). Keep it simple — a helper that creates an `<a download>` from the dataURL.

Mirror the existing finance report pages (`resources/js/Pages/Finance/Reports/*`) for layout tokens and the date-filter/CSV-link pattern.

- [ ] **Step 2: Add nav + hub link**

- In `resources/js/Layouts/AuthenticatedLayout.vue`, add an **Analytics** entry under the Finance section, `visible: can('finance.analytics.view')` (mirror the existing finance nav gating). 
- In `resources/js/Pages/Finance/Hub.vue`, add an "Analytics" link in the header link row (mirror the existing Reports/Budgets links), pointing to `route('finance.analytics')`.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

- [ ] **Step 4: Regression gate**

Run: `php artisan test tests/Feature/Finance`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — accessibility green (filter inputs carry `aria-label`); the param-less `finance/analytics` route renders for the smoke test; allow only the known `KioskRecentTest` flake.

Run: `php artisan migrate:fresh --seed`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Finance/Analytics/Dashboard.vue resources/js/Pages/Finance/Hub.vue resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(finance): analytics dashboard page (KPIs + charts + filters + export) + nav"
git commit --allow-empty -m "test(finance): finance analytics dashboard regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Read-only & additive**: the service only reads; the dashboard never mutates. Existing finance/learning/payroll suites must stay green.
- **RBAC**: every route + the nav + the hub link gated by `finance.analytics.view`; super_admin/ceo pass via the `*` wildcard (no special-casing needed). The endpoint test asserts finance_officer/auditor/super_admin in, employee out.
- **Cash & aging reuse the canonical definitions**: cash accounts = org bank GLs + 1010/1130 (mirrors CashFlowReport); income/expenditure use `LedgerBalanceService::activity` natural balances (posted+reversed).
- **Chart sizing**: wrappers set `maintainAspectRatio:false`; the page must give each chart a fixed-height container.
- **PNG export is client-side** (Chart.js `toBase64Image`); the PDF is a server-side data snapshot (no canvases).
- **Accessibility**: fiscal-year + from/to inputs carry `aria-label`.
- **Smoke test**: `finance/analytics` is param-less and must render with defaults (current year, FY-start→today).

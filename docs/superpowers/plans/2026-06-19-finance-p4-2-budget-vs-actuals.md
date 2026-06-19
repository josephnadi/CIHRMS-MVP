# Finance Phase 4 — P4-2: Budget vs Actuals Report

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Report each account's year-to-date budget against its actual ledger activity for a fiscal year — annual / YTD budget vs YTD actual, with a favourable/unfavourable variance flag — so finance can see budget performance at a glance.

**Architecture:** A `BudgetVsActualsReport` presenter reads the year's `budget_lines` (P4-1) and `LedgerBalanceService::activity` (Phase 3) for `[Jan 1 … end of as-of period]`, derives `ytd_budget = annual/12 × periodNo`, compares against `ytd_actual` (natural balance), and computes `variance = ytd_budget − ytd_actual` plus a type-aware `favourable` flag. It reuses the Phase 3 report scaffolding (presenter → `ReportController` → Inertia/Vue → CSV/PDF), gated by the existing `finance.reports.view`.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest, barryvdh/laravel-dompdf.

## Global Constraints

- Read-only: the report and its presenter NEVER mutate the ledger or create a budget. Resolve the budget by lookup (do NOT call `BudgetService::forYear`, which would create a draft).
- `declare(strict_types=1)` on new PHP classes.
- Every form/date input carries an `aria-label` (the `AccessibilityAuditorTest` gate fails otherwise).
- Download routes (`.csv`/`.pdf`) are skipped by `AuthenticatedRoutesSmokeTest` — keep the `export.csv` / `export.pdf` suffix so the existing `preg_match` skip applies.
- Account-type enum values are lowercase: `asset`, `liability`, `equity`, `income`, `expense` (confirm in `app/Enums/GlAccountType.php` before coding).

**This is P4-2 of Phase 4.** P4-1 (budget model + entry/approval) is merged. P4-3 (soft controls) follows.

**Spec:** `docs/superpowers/specs/2026-06-19-finance-budgeting-design.md` (section "P4-2 — Budget vs Actuals report").

---

### Task 1: BudgetVsActualsReport presenter

**Files:**
- Create: `app/Services/Finance/Reports/BudgetVsActualsReport.php`
- Test: `tests/Feature/Finance/BudgetVsActualsReportTest.php`

**Interfaces:**
- Consumes: `LedgerBalanceService::activity(CarbonInterface $from, CarbonInterface $to): Collection` (rows keyed by `code` after `->keyBy('code')`, each with `code,name,type,natural_balance`); `Budget`/`BudgetLine` (P4-1); `GlAccount` (`code`, `name`, `type->value`).
- Produces: `BudgetVsActualsReport::forYear(int $year, int $asOfPeriodNo = 12): array` returning:
  ```
  [
    'year' => int, 'as_of_period' => int, 'as_of' => 'YYYY-MM-DD',
    'has_budget' => bool,                       // a budget row exists for the year
    'groups' => [                               // canonical type order, only non-empty types
      ['type' => 'income', 'rows' => [ ['code','name','type','annual_budget','ytd_budget','ytd_actual','variance','favourable'], ... ],
        'annual_budget'=>float,'ytd_budget'=>float,'ytd_actual'=>float,'variance'=>float], ...
    ],
    'totals' => ['annual_budget'=>float,'ytd_budget'=>float,'ytd_actual'=>float,'variance'=>float],
  ]
  ```
  `favourable` is `true|false|null` (null = informational for asset/liability/equity).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Finance\Reports\BudgetVsActualsReport;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

/** Post a one-sided actual onto an account via a balanced manual JE (other leg = cash 1100). */
function postActual(string $code, float $amount, string $date): void
{
    $acc  = GlAccount::where('code', $code)->firstOrFail();
    $cash = GlAccount::where('code', '1100')->firstOrFail();
    $isDebitNatural = in_array($acc->type->value, ['asset', 'expense'], true);

    $je = JournalEntry::create([
        'reference' => 'JE-BVA-' . $code . '-' . $date, 'entry_date' => $date, 'narration' => 'bva',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => App\Models\User::factory()->create()->id,
    ]);
    // account leg
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $acc->id,
        'debit_amount' => $isDebitNatural ? $amount : 0, 'credit_amount' => $isDebitNatural ? 0 : $amount]);
    // balancing cash leg
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cash->id,
        'debit_amount' => $isDebitNatural ? 0 : $amount, 'credit_amount' => $isDebitNatural ? $amount : 0]);
}

function budgetYear(int $year, array $codeToAnnual): void
{
    $fy = FiscalYear::firstOrCreate(['year' => $year],
        ['status' => 'open', 'starts_on' => "$year-01-01", 'ends_on' => "$year-12-31"]);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'approved']);
    foreach ($codeToAnnual as $code => $annual) {
        BudgetLine::create(['budget_id' => $budget->id,
            'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'annual_amount' => $annual]);
    }
}

it('spreads the annual budget evenly and compares YTD actual', function () {
    budgetYear(2026, ['5100' => 120000]); // Salaries (expense): 10,000/month
    postActual('5100', 25000, '2026-03-15'); // spend 25k by end of Q1

    $report = app(BudgetVsActualsReport::class)->forYear(2026, 3); // as of period 3 (March)

    $row = collect($report['groups'])->firstWhere('type', 'expense')['rows'][0];
    expect($row['code'])->toBe('5100')
        ->and($row['annual_budget'])->toBe(120000.0)
        ->and($row['ytd_budget'])->toBe(30000.0)   // 120000/12 * 3
        ->and($row['ytd_actual'])->toBe(25000.0)
        ->and($row['variance'])->toBe(5000.0)       // 30000 - 25000, under budget
        ->and($row['favourable'])->toBeTrue();      // expense under budget = favourable
});

it('flags an over-spent expense as unfavourable and at-target income as favourable', function () {
    budgetYear(2026, ['5100' => 120000, '4100' => 60000]);
    postActual('5100', 130000, '2026-06-20'); // overspent for full year
    postActual('4100', 70000, '2026-06-20');  // income over target

    $report = app(BudgetVsActualsReport::class)->forYear(2026, 12);

    $exp = collect($report['groups'])->firstWhere('type', 'expense')['rows'][0];
    $inc = collect($report['groups'])->firstWhere('type', 'income')['rows'][0];

    expect($exp['variance'])->toBe(-10000.0)        // 120000 - 130000
        ->and($exp['favourable'])->toBeFalse()       // expense over budget = unfavourable
        ->and($inc['favourable'])->toBeTrue();       // income at/over target = favourable
});

it('includes an un-budgeted account that has actuals (budget 0, actual surfaces)', function () {
    budgetYear(2026, ['5100' => 120000]);
    postActual('5110', 4000, '2026-02-10'); // Allowances (expense) with NO budget line

    $report = app(BudgetVsActualsReport::class)->forYear(2026, 12);
    $rows = collect($report['groups'])->firstWhere('type', 'expense')['rows'];
    $row  = collect($rows)->firstWhere('code', '5110');

    expect($row)->not->toBeNull()
        ->and($row['annual_budget'])->toBe(0.0)
        ->and($row['ytd_actual'])->toBe(4000.0)
        ->and($row['favourable'])->toBeFalse();      // 0 budget, 4000 spent = unfavourable
});

it('reports zero everything and has_budget=false when no budget exists', function () {
    $report = app(BudgetVsActualsReport::class)->forYear(2030, 12);
    expect($report['has_budget'])->toBeFalse()
        ->and($report['totals']['annual_budget'])->toBe(0.0)
        ->and($report['groups'])->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetVsActualsReportTest.php`
Expected: FAIL — presenter missing.

- [ ] **Step 3: Confirm the account-type enum values**

Read `app/Enums/GlAccountType.php` and confirm the backing values are exactly `asset`, `liability`, `equity`, `income`, `expense` (lowercase). The presenter's canonical group order and `favourable` logic depend on these strings.

- [ ] **Step 4: Write the presenter**

`app/Services/Finance/Reports/BudgetVsActualsReport.php`:

```php
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

        $codes = collect(array_keys($budgetByCode))->merge($actuals->keys())
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/BudgetVsActualsReportTest.php`
Expected: PASS (all four).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/Reports/BudgetVsActualsReport.php tests/Feature/Finance/BudgetVsActualsReportTest.php
git commit -m "feat(finance): BudgetVsActualsReport (even spread, YTD variance, favourable by type)"
```

---

### Task 2: Report page + CSV/PDF + routes + cross-links

**Files:**
- Modify: `app/Http/Controllers/Finance/ReportController.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Finance/Reports/BudgetVsActuals.vue`
- Create: `resources/views/finance/reports/budget-vs-actuals-pdf.blade.php`
- Modify: `resources/js/Pages/Finance/Hub.vue`
- Test: `tests/Feature/Finance/BudgetVsActualsEndpointTest.php`

**Interfaces:**
- Consumes: `BudgetVsActualsReport::forYear(int, int)` (Task 1); existing `finance.reports.view` permission; the `Pdf` facade + `streamDownload` pattern already used in `ReportController`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();

    $fy = FiscalYear::firstOrCreate(['year' => 2026],
        ['status' => 'open', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'approved']);
    BudgetLine::create(['budget_id' => $budget->id,
        'gl_account_id' => GlAccount::where('code', '5100')->value('id'), 'annual_amount' => 120000]);
});

it('renders the budget vs actuals report for a finance_officer', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/budget-vs-actuals?year=2026&period=12')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/BudgetVsActuals')
            ->where('report.has_budget', true)
            ->where('year', 2026));
});

it('lets an auditor view it (finance.reports.view) but forbids an employee', function () {
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->get('/finance/reports/budget-vs-actuals?year=2026')->assertOk();
    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/finance/reports/budget-vs-actuals?year=2026')->assertForbidden();
});

it('exports budget vs actuals as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/budget-vs-actuals/export.csv?year=2026&period=12');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
});

it('exports budget vs actuals as PDF', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/budget-vs-actuals/export.pdf?year=2026&period=12');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetVsActualsEndpointTest.php`
Expected: FAIL — route/controller/page missing.

- [ ] **Step 3: Add the controller dependency + actions**

In `app/Http/Controllers/Finance/ReportController.php`, add the presenter to the constructor (after `$cashFlow`):

```php
        private readonly \App\Services\Finance\Reports\BudgetVsActualsReport $budgetVsActuals,
```

Add a private helper for the `(year, period)` query parse (place it next to `periodRange`):

```php
    /** @return array{0:int,1:int} [year, periodNo] */
    private function budgetYearPeriod(Request $request): array
    {
        $year   = (int) ($request->query('year') ?: CarbonImmutable::today()->year);
        $period = (int) ($request->query('period') ?: 12);

        return [$year, max(1, min(12, $period))];
    }
```

Add the three actions (place after the cash-flow actions):

```php
    public function budgetVsActuals(Request $request): Response
    {
        [$year, $period] = $this->budgetYearPeriod($request);

        return Inertia::render('Finance/Reports/BudgetVsActuals', [
            'activeModule' => 'finance-reports',
            'year'         => $year,
            'period'       => $period,
            'report'       => $this->budgetVsActuals->forYear($year, $period),
        ]);
    }

    public function budgetVsActualsCsv(Request $request): StreamedResponse
    {
        [$year, $period] = $this->budgetYearPeriod($request);
        $report = $this->budgetVsActuals->forYear($year, $period);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Type', 'Code', 'Account', 'Annual budget', 'YTD budget', 'YTD actual', 'Variance', 'Status']);
            foreach ($report['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    fputcsv($out, [$row['type'], $row['code'], $row['name'], $row['annual_budget'],
                        $row['ytd_budget'], $row['ytd_actual'], $row['variance'],
                        $row['favourable'] === null ? '' : ($row['favourable'] ? 'Favourable' : 'Unfavourable')]);
                }
                fputcsv($out, [strtoupper($group['type']) . ' total', '', '', $group['annual_budget'],
                    $group['ytd_budget'], $group['ytd_actual'], $group['variance'], '']);
            }
            fputcsv($out, ['GRAND TOTAL', '', '', $report['totals']['annual_budget'],
                $report['totals']['ytd_budget'], $report['totals']['ytd_actual'], $report['totals']['variance'], '']);
            fclose($out);
        }, "budget-vs-actuals-{$report['year']}-p{$report['as_of_period']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function budgetVsActualsPdf(Request $request): HttpResponse
    {
        [$year, $period] = $this->budgetYearPeriod($request);
        $report = $this->budgetVsActuals->forYear($year, $period);

        return Pdf::loadView('finance.reports.budget-vs-actuals-pdf', ['report' => $report])
            ->download("budget-vs-actuals-{$report['year']}-p{$report['as_of_period']}.pdf");
    }
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `Route::middleware('permission:finance.reports.view')->group(...)` block (after the cash-flow routes), add:

```php
            Route::get('reports/budget-vs-actuals',             [\App\Http\Controllers\Finance\ReportController::class, 'budgetVsActuals'])->name('reports.budget-vs-actuals');
            Route::get('reports/budget-vs-actuals/export.csv',  [\App\Http\Controllers\Finance\ReportController::class, 'budgetVsActualsCsv'])->name('reports.budget-vs-actuals.csv');
            Route::get('reports/budget-vs-actuals/export.pdf',  [\App\Http\Controllers\Finance\ReportController::class, 'budgetVsActualsPdf'])->name('reports.budget-vs-actuals.pdf');
```

(If the cash-flow CSV route isn't already in this group, just add these three anywhere inside the `finance.reports.view` group.)

- [ ] **Step 5: Write the Vue page**

`resources/js/Pages/Finance/Reports/BudgetVsActuals.vue`:

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    year:   { type: Number, required: true },
    period: { type: Number, required: true },
    report: { type: Object, required: true },
});

const year = ref(props.year);
const period = ref(props.period);

const apply = () => router.get(route('finance.reports.budget-vs-actuals'),
    { year: year.value, period: period.value }, { preserveState: false });

const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const unfavourable = (row) => row.favourable === false;
const typeLabel = (t) => t.charAt(0).toUpperCase() + t.slice(1);
</script>

<template>
    <Head title="Budget vs Actuals" />

    <div class="p-6 max-w-5xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Budget vs Actuals</h1>
                <p class="text-on-surface-variant text-sm mt-1">Fiscal year {{ year }} · through period {{ report.as_of_period }} ({{ report.as_of }})</p>
            </div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>Year
                    <input type="number" v-model.number="year" aria-label="Fiscal year"
                           class="mt-1 block w-24 rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <label>Through period
                    <input type="number" min="1" max="12" v-model.number="period" aria-label="As-of period number"
                           class="mt-1 block w-20 rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
                <a :href="route('finance.reports.budget-vs-actuals.csv', { year, period })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">CSV</a>
                <a :href="route('finance.reports.budget-vs-actuals.pdf', { year, period })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">PDF</a>
            </div>
        </header>

        <p v-if="!report.has_budget" class="mb-4 rounded-lg border border-amber-400/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
            No budget exists for {{ year }} — actuals are shown against a zero budget.
        </p>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-6">
            <section v-for="group in report.groups" :key="group.type">
                <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-2">{{ typeLabel(group.type) }}</h2>
                <table class="w-full text-sm">
                    <thead class="text-on-surface-variant text-[11px] uppercase">
                        <tr>
                            <th class="text-left p-2">Account</th>
                            <th class="text-right p-2">Annual budget</th>
                            <th class="text-right p-2">YTD budget</th>
                            <th class="text-right p-2">YTD actual</th>
                            <th class="text-right p-2">Variance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="r in group.rows" :key="r.code" :class="unfavourable(r) ? 'bg-rose-500/5' : ''">
                            <td class="p-2 text-primary">{{ r.code }} {{ r.name }}</td>
                            <td class="p-2 text-right text-on-surface-variant">{{ money(r.annual_budget) }}</td>
                            <td class="p-2 text-right text-on-surface-variant">{{ money(r.ytd_budget) }}</td>
                            <td class="p-2 text-right text-primary">{{ money(r.ytd_actual) }}</td>
                            <td class="p-2 text-right font-bold" :class="unfavourable(r) ? 'text-rose-300' : 'text-emerald-300'">{{ money(r.variance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="font-black border-t border-outline-variant/50">
                        <tr>
                            <td class="p-2">Total {{ typeLabel(group.type) }}</td>
                            <td class="p-2 text-right">{{ money(group.annual_budget) }}</td>
                            <td class="p-2 text-right">{{ money(group.ytd_budget) }}</td>
                            <td class="p-2 text-right">{{ money(group.ytd_actual) }}</td>
                            <td class="p-2 text-right">{{ money(group.variance) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <div class="flex justify-between border-t border-outline-variant/60 pt-3 font-black text-primary">
                <span>Grand total variance</span>
                <span>{{ money(report.totals.variance) }}</span>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Write the PDF blade**

`resources/views/finance/reports/budget-vs-actuals-pdf.blade.php`:

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 16px; margin: 0 0 2px; } .sub { color: #666; margin: 0 0 12px; }
h2 { font-size: 12px; margin: 12px 0 4px; text-transform: uppercase; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
th { text-align: left; background: #f3f3f3; }
.r { text-align: right; } tfoot td { font-weight: bold; border-top: 2px solid #333; }
.bad { color: #b00020; font-weight: bold; }
</style></head>
<body>
    <h1>Budget vs Actuals</h1>
    <p class="sub">Fiscal year {{ $report['year'] }} — through period {{ $report['as_of_period'] }} ({{ $report['as_of'] }})</p>

    @foreach ($report['groups'] as $group)
        <h2>{{ ucfirst($group['type']) }}</h2>
        <table>
            <thead><tr><th>Account</th><th class="r">Annual</th><th class="r">YTD budget</th><th class="r">YTD actual</th><th class="r">Variance</th></tr></thead>
            <tbody>
            @foreach ($group['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                    <td class="r">{{ number_format($row['annual_budget'], 2) }}</td>
                    <td class="r">{{ number_format($row['ytd_budget'], 2) }}</td>
                    <td class="r">{{ number_format($row['ytd_actual'], 2) }}</td>
                    <td class="r {{ $row['favourable'] === false ? 'bad' : '' }}">{{ number_format($row['variance'], 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr>
                <td>Total {{ ucfirst($group['type']) }}</td>
                <td class="r">{{ number_format($group['annual_budget'], 2) }}</td>
                <td class="r">{{ number_format($group['ytd_budget'], 2) }}</td>
                <td class="r">{{ number_format($group['ytd_actual'], 2) }}</td>
                <td class="r">{{ number_format($group['variance'], 2) }}</td>
            </tr></tfoot>
        </table>
    @endforeach

    <p style="margin-top:12px;font-weight:bold;">Grand total variance: {{ number_format($report['totals']['variance'], 2) }}</p>
</body>
</html>
```

- [ ] **Step 7: Cross-link from the Finance Hub**

In `resources/js/Pages/Finance/Hub.vue`, inside the `<div class="flex gap-2">` header block (after the Budgets link added in P4-1), add:

```vue
                <Link :href="route('finance.reports.budget-vs-actuals')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">monitoring</span>
                    Budget vs Actuals
                </Link>
```

- [ ] **Step 8: Build + run the test**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

Run: `php artisan test tests/Feature/Finance/BudgetVsActualsEndpointTest.php`
Expected: PASS (all four).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Finance/ReportController.php routes/web.php resources/js/Pages/Finance/Reports/BudgetVsActuals.vue resources/views/finance/reports/budget-vs-actuals-pdf.blade.php resources/js/Pages/Finance/Hub.vue tests/Feature/Finance/BudgetVsActualsEndpointTest.php
git commit -m "feat(finance): budget vs actuals report page + CSV/PDF export"
```

---

### Task 3: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Finance suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance`
Expected: PASS.

- [ ] **Step 2: Full app suite**

Run: `php artisan test`
Expected: PASS — accessibility green (year + period inputs carry `aria-label`); the routes smoke test green (the export routes carry `.csv`/`.pdf`). Allow only the known `KioskRecentTest` time-of-day flake if it is the sole failure.

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P4-2 budget vs actuals regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Read-only**: the presenter resolves the budget by `Budget::whereHas('fiscalYear', year=...)` — it MUST NOT call `BudgetService::forYear` (which creates a draft). No actuals are mutated.
- **Even spread is derived**: `ytd_budget = annual/12 × periodNo`; nothing is stored per month. Changing the annual figure reflects instantly.
- **Variance = ytd_budget − ytd_actual**; `favourable` is type-aware (expense under-spent / income at-or-over-target) and is `null` for asset/liability/equity (informational, never flagged red).
- **Union of budgeted + actual-only accounts** so over-spend on an un-budgeted account surfaces (budget 0, actual > 0 → unfavourable). Codes are merged as list values (not associative keys) so there is no numeric-key reindex pitfall.
- **Accessibility**: the year and period inputs carry `aria-label`.
- **Downloads**: routes end in `export.csv` / `export.pdf` so `AuthenticatedRoutesSmokeTest` skips them.
- **P4-3** will add `BudgetStatusService::remaining()` (advisory, non-blocking) + a variance-alerts surface reusing this report's unfavourable rows.
```
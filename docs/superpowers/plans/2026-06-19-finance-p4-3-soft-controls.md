# Finance Phase 4 — P4-3: Soft Budget Controls

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add advisory (never-blocking) budget controls — a `remaining(account, asOf)` figure any form can consult, and an over-budget alerts surface on the Budget vs Actuals page — completing Phase 4 and the Finance roadmap.

**Architecture:** A `BudgetStatusService` exposes `remaining()` (approved annual budget − actuals-to-date for an account) and `overBudgetAlerts()` (the unfavourable rows from `BudgetVsActualsReport`, worst-first). The alerts feed a panel at the top of the existing Budget vs Actuals report page. Nothing here touches the posting path — it is purely advisory.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Global Constraints

- **Non-blocking by contract**: nothing in this plan is wired into `PostingService`/`JournalPostingService` or any posting path. `remaining()` and `overBudgetAlerts()` are read-only advisory queries.
- Read-only: resolve budgets by lookup; never call `BudgetService::forYear` (which creates a draft). No ledger writes.
- `declare(strict_types=1)` on new PHP classes.
- `remaining()` considers only an **Approved** budget for the as-of year (a draft/absent budget → annual 0).
- Account-type enum values are lowercase (`asset`/`liability`/`equity`/`income`/`expense`).

**This is P4-3 of Phase 4 — the final increment.** P4-1 (budget model) and P4-2 (budget vs actuals report) are merged.

**Spec:** `docs/superpowers/specs/2026-06-19-finance-budgeting-design.md` (section "P4-3 — Controls (soft, non-blocking)").

---

### Task 1: BudgetStatusService (remaining + alerts)

**Files:**
- Create: `app/Services/Finance/BudgetStatusService.php`
- Test: `tests/Feature/Finance/BudgetStatusServiceTest.php`

**Interfaces:**
- Consumes: `LedgerBalanceService::activity(CarbonInterface, CarbonInterface): Collection` (rows with `code`, `natural_balance`); `BudgetVsActualsReport::forYear(int, int): array` (P4-2; `groups[].rows[]` each with `code,name,type,annual_budget,ytd_budget,ytd_actual,variance,favourable`); `Budget`/`BudgetStatus`/`BudgetLine` (P4-1); `GlAccount` (`id`, `code`).
- Produces:
  - `BudgetStatusService::remaining(GlAccount $account, CarbonInterface $asOf): float` — `approvedAnnualBudget − actualToDate`; positive = budget left, negative = over budget. Advisory only.
  - `BudgetStatusService::overBudgetAlerts(int $year, int $asOfPeriodNo = 12): array` — the `favourable === false` rows from `BudgetVsActualsReport`, worst (most negative variance) first; each `['code','name','type','ytd_budget','ytd_actual','variance']`.

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
use App\Models\User;
use App\Services\Finance\BudgetStatusService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function approvedBudget(int $year, array $codeToAnnual): void
{
    $fy = FiscalYear::firstOrCreate(['year' => $year],
        ['status' => 'open', 'starts_on' => "$year-01-01", 'ends_on' => "$year-12-31"]);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'approved']);
    foreach ($codeToAnnual as $code => $annual) {
        BudgetLine::create(['budget_id' => $budget->id,
            'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'annual_amount' => $annual]);
    }
}

function spend(string $code, float $amount, string $date): void
{
    $acc  = GlAccount::where('code', $code)->firstOrFail();
    $cash = GlAccount::where('code', '1100')->firstOrFail();
    $debitNatural = in_array($acc->type->value, ['asset', 'expense'], true);

    $je = JournalEntry::create([
        'reference' => 'JE-BSS-' . $code . '-' . $date, 'entry_date' => $date, 'narration' => 'bss',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $acc->id,
        'debit_amount' => $debitNatural ? $amount : 0, 'credit_amount' => $debitNatural ? 0 : $amount]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cash->id,
        'debit_amount' => $debitNatural ? 0 : $amount, 'credit_amount' => $debitNatural ? $amount : 0]);
}

it('reports remaining budget as approved annual minus actuals to date', function () {
    approvedBudget(2026, ['5100' => 120000]);
    spend('5100', 30000, '2026-04-10');

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBe(90000.0); // 120000 - 30000
});

it('returns a negative remaining when over budget (advisory, still computed)', function () {
    approvedBudget(2026, ['5100' => 100000]);
    spend('5100', 130000, '2026-05-01');

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    expect($svc->remaining($acc, CarbonImmutable::create(2026, 12, 31)))->toBe(-30000.0);
});

it('treats a draft or absent budget as zero annual budget', function () {
    // No approved budget for 2026 at all.
    spend('5100', 5000, '2026-03-01');

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBe(-5000.0); // 0 - 5000
});

it('never blocks: an over-budget account can still be posted to', function () {
    approvedBudget(2026, ['5100' => 1000]);
    spend('5100', 5000, '2026-02-01'); // already way over

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();
    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBeLessThan(0.0);

    // A further posting succeeds without any budget guard throwing.
    spend('5100', 2000, '2026-06-01');
    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBe(-6000.0); // 1000 - 7000
});

it('lists over-budget accounts worst-first, excluding favourable and neutral rows', function () {
    approvedBudget(2026, ['5100' => 100000, '5110' => 50000, '4100' => 60000]);
    spend('5100', 130000, '2026-06-01'); // expense over by 30k (worst)
    spend('5110', 60000, '2026-06-01');  // expense over by 10k
    spend('4100', 70000, '2026-06-01');  // income over target → favourable, NOT an alert

    $alerts = app(BudgetStatusService::class)->overBudgetAlerts(2026, 12);

    expect($alerts)->toHaveCount(2)
        ->and($alerts[0]['code'])->toBe('5100')        // most negative variance first
        ->and($alerts[0]['variance'])->toBe(-30000.0)
        ->and($alerts[1]['code'])->toBe('5110')
        ->and(collect($alerts)->pluck('code')->all())->not->toContain('4100');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetStatusServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

`app/Services/Finance/BudgetStatusService.php`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/BudgetStatusServiceTest.php`
Expected: PASS (all five).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/BudgetStatusService.php tests/Feature/Finance/BudgetStatusServiceTest.php
git commit -m "feat(finance): BudgetStatusService (advisory remaining + over-budget alerts)"
```

---

### Task 2: Surface alerts on the Budget vs Actuals page

**Files:**
- Modify: `app/Http/Controllers/Finance/ReportController.php`
- Modify: `resources/js/Pages/Finance/Reports/BudgetVsActuals.vue`
- Test: `tests/Feature/Finance/BudgetVsActualsEndpointTest.php`

**Interfaces:**
- Consumes: `BudgetStatusService::overBudgetAlerts(int, int)` (Task 1).

- [ ] **Step 1: Add the failing assertion**

Append this test to `tests/Feature/Finance/BudgetVsActualsEndpointTest.php` (the `beforeEach` already seeds an approved 2026 budget with a 120000 line on 5100):

```php
it('passes over-budget alerts to the report page', function () {
    // Overspend 5100 well past its 120000 budget.
    $acc  = GlAccount::where('code', '5100')->firstOrFail();
    $cash = GlAccount::where('code', '1100')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-ALERT-1', 'entry_date' => '2026-05-01', 'narration' => 'over',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $acc->id, 'debit_amount' => 200000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 200000]);

    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/budget-vs-actuals?year=2026&period=12')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/BudgetVsActuals')
            ->where('alerts.0.code', '5100')
            ->where('alerts.0.variance', fn ($v) => (float) $v < 0));
});
```

This test references `JournalEntry` and `JournalLine`; add their `use` imports at the top of the test file if not already present:

```php
use App\Models\JournalEntry;
use App\Models\JournalLine;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetVsActualsEndpointTest.php`
Expected: the new test FAILS (`alerts` prop missing); the four existing tests still pass.

- [ ] **Step 3: Inject the service + pass alerts**

In `app/Http/Controllers/Finance/ReportController.php`, add `BudgetStatusService` to the constructor (after the `BudgetVsActualsReport` param added in P4-2):

```php
        private readonly \App\Services\Finance\BudgetStatusService $budgetStatus,
```

In the `budgetVsActuals()` action, add the `alerts` prop:

```php
    public function budgetVsActuals(Request $request): Response
    {
        [$year, $period] = $this->budgetYearPeriod($request);

        return Inertia::render('Finance/Reports/BudgetVsActuals', [
            'activeModule' => 'finance-reports',
            'year'         => $year,
            'period'       => $period,
            'report'       => $this->budgetVsActuals->forYear($year, $period),
            'alerts'       => $this->budgetStatus->overBudgetAlerts($year, $period),
        ]);
    }
```

- [ ] **Step 4: Render the alerts panel**

In `resources/js/Pages/Finance/Reports/BudgetVsActuals.vue`, add `alerts` to the props:

```js
const props = defineProps({
    year:   { type: Number, required: true },
    period: { type: Number, required: true },
    report: { type: Object, required: true },
    alerts: { type: Array,  default: () => [] },
});
```

Then add the panel immediately after the existing `has_budget` warning `<p>` and before the main `<div class="rounded-2xl ...">` results block:

```vue
        <section v-if="alerts.length" class="mb-4 rounded-2xl border border-rose-400/40 bg-rose-500/10 p-4">
            <h2 class="text-sm font-black uppercase tracking-wide text-rose-200 mb-2">
                <span class="material-symbols-outlined align-middle text-[16px]">warning</span>
                Over-budget alerts ({{ alerts.length }})
            </h2>
            <table class="w-full text-sm">
                <thead class="text-rose-200/70 text-[11px] uppercase">
                    <tr><th class="text-left p-1.5">Account</th><th class="text-right p-1.5">YTD budget</th><th class="text-right p-1.5">YTD actual</th><th class="text-right p-1.5">Over by</th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in alerts" :key="a.code">
                        <td class="p-1.5 text-primary">{{ a.code }} {{ a.name }}</td>
                        <td class="p-1.5 text-right text-on-surface-variant">{{ money(a.ytd_budget) }}</td>
                        <td class="p-1.5 text-right text-primary">{{ money(a.ytd_actual) }}</td>
                        <td class="p-1.5 text-right font-bold text-rose-300">{{ money(-a.variance) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
```

(`money` is already defined in this component.)

- [ ] **Step 5: Build + run the test**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

Run: `php artisan test tests/Feature/Finance/BudgetVsActualsEndpointTest.php`
Expected: PASS (all five now).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Finance/ReportController.php resources/js/Pages/Finance/Reports/BudgetVsActuals.vue tests/Feature/Finance/BudgetVsActualsEndpointTest.php
git commit -m "feat(finance): over-budget alerts panel on budget vs actuals page"
```

---

### Task 3: Regression gate (closes Phase 4)

**Files:** none (verification only).

- [ ] **Step 1: Finance suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance`
Expected: PASS.

- [ ] **Step 2: Full app suite**

Run: `php artisan test`
Expected: PASS — accessibility green; routes smoke green. Allow only the known `KioskRecentTest` time-of-day flake if it is the sole failure.

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P4-3 soft controls regression gate green (Phase 4 complete)"
```

---

## Self-Review notes (for the implementer)

- **Never blocks**: nothing here is referenced by `PostingService`/`JournalPostingService`. The "never blocks" test posts past budget and still succeeds. `remaining()`/`overBudgetAlerts()` are read-only.
- **`remaining()` uses the full annual budget** (not the YTD-prorated figure) less actuals-to-date, per the spec — it answers "how much of this year's appropriation is left," and only an **Approved** budget counts.
- **Alerts reuse P4-2**: `overBudgetAlerts` filters the `favourable === false` rows from `BudgetVsActualsReport` and sorts worst-first; income-over-target (favourable) and balance-sheet (null) rows are excluded.
- **Accessibility**: no new form inputs added in Task 2 (the panel is display-only), so the `AccessibilityAuditorTest` gate is unaffected.
- **Phase 4 complete** after this: budgets (P4-1) → budget vs actuals (P4-2) → soft controls (P4-3). The Finance "source of all monetary throughput" roadmap (Phases 1–4) is done.
```
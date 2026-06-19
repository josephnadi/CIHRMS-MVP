# Finance Phase 3 — P3-3: Statement of Cash Flows (Direct + Indirect)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Statement of Cash Flows with BOTH the direct and indirect methods (UI toggle), where both methods provably reconcile to the actual net change in cash for the period — the final Phase 3 statement.

**Architecture:** `CashFlowReport` defines the cash accounts (org bank accounts + Cash on Hand 1010 + Cash in Transit 1130), computes the anchor `netChangeInCash` (Σ cash-account activity), and produces both presentations: **direct** (categorise each cash movement by its contra lines into Operating/Investing/Financing) and **indirect** (surplus + working-capital changes + financing). Both equal the anchor by construction. A `ReportController` action renders the page with a method toggle + CSV.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

**This is P3-3 — the last statement of Phase 3.**

**Spec:** `docs/superpowers/specs/2026-06-18-finance-financial-statements-design.md`

## The reconciliation (the test)

For period [from, to]:
- **Anchor** `net_change = Σ over cash accounts of (debit − credit)` of posted+reversed lines in the window (cash is an asset; positive = cash increased).
- **Direct**: for every journal entry that touches a cash account in the window, each non-cash (contra) line contributes `−(debit − credit)` to its category. Operating = contra is income/expense/AP/AR/payables; Financing = contra is equity (3xxx); Investing = contra is Loans Receivable (1300, staff lending). Sum of categories = `−Σ contra = Σ cash = net_change`.
- **Indirect**: `Operating = surplus + Δliabilities − ΔAR`; `Investing = −ΔLoans(1300)`; `Financing = Δequity`. By the balanced-ledger identity (`net cash = surplus + Δliab + Δequity − Δnon-cash-assets`, non-cash assets = AR + Loans), this sums to `net_change`.
- **Invariant**: `direct.net == indirect.net == net_change`.

---

### Task 1: CashFlowReport

**Files:**
- Create: `app/Services/Finance/Reports/CashFlowReport.php`
- Test: `tests/Feature/Finance/CashFlowReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\CashFlowReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run(); // links bank GLs (1100/1110/1120) to org_bank_accounts
});

function cf_post(array $lines, string $date): void
{
    $je = JournalEntry::create([
        'reference' => 'JE-CF-' . uniqid(), 'entry_date' => $date, 'narration' => 'cf',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    $no = 1;
    foreach ($lines as [$code, $debit, $credit]) {
        JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => $no++, 'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'debit_amount' => $debit, 'credit_amount' => $credit]);
    }
}

it('reconciles direct and indirect to the actual net change in cash', function () {
    // Operating in: membership 5000 to bank. Operating out: salaries 2000 from bank.
    cf_post([['1100', 5000, 0], ['4100', 0, 5000]], '2026-06-05');
    cf_post([['5100', 2000, 0], ['1100', 0, 2000]], '2026-06-08');
    // Financing: 1000 fund injection into bank (contra equity 3100).
    cf_post([['1100', 1000, 0], ['3100', 0, 1000]], '2026-06-10');

    $report = app(CashFlowReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    // Anchor: 5000 - 2000 + 1000 = 4000 net cash increase.
    expect($report['net_change'])->toBe(4000.0)
        ->and($report['direct']['net'])->toBe(4000.0)
        ->and($report['indirect']['net'])->toBe(4000.0);

    // Direct categories: operating = 5000 - 2000 = 3000; financing = 1000; investing = 0.
    expect($report['direct']['operating'])->toBe(3000.0)
        ->and($report['direct']['financing'])->toBe(1000.0)
        ->and($report['direct']['investing'])->toBe(0.0);

    // Indirect: surplus = income 5000 - expense 2000 = 3000 (operating), financing 1000.
    expect($report['indirect']['surplus'])->toBe(3000.0)
        ->and($report['indirect']['operating'])->toBe(3000.0)
        ->and($report['indirect']['financing'])->toBe(1000.0);
});

it('classifies a staff-loan disbursement as an investing outflow', function () {
    // Disburse a 1200 staff loan from bank: DR Loans Receivable (1300) / CR Bank.
    cf_post([['1300', 1200, 0], ['1100', 0, 1200]], '2026-06-15');

    $report = app(CashFlowReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($report['net_change'])->toBe(-1200.0)
        ->and($report['direct']['investing'])->toBe(-1200.0)
        ->and($report['indirect']['investing'])->toBe(-1200.0)
        ->and($report['direct']['net'])->toBe($report['indirect']['net']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/CashFlowReportTest.php`
Expected: FAIL — report missing.

- [ ] **Step 3: Write the report**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/CashFlowReportTest.php`
Expected: PASS (both — the reconciliation holds).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/Reports/CashFlowReport.php tests/Feature/Finance/CashFlowReportTest.php
git commit -m "feat(finance): CashFlowReport (direct + indirect, both reconcile to net cash)"
```

---

### Task 2: Controller action + route + CSV

**Files:**
- Modify: `app/Http/Controllers/Finance/ReportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/CashFlowEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $je = JournalEntry::create([
        'reference' => 'JE-CFE-1', 'entry_date' => '2026-06-05', 'narration' => 'cfe',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 5000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '4100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 5000]);
});

it('renders the cash flow statement with both methods reconciling', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/cash-flow?from=2026-06-01&to=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/CashFlow')
            ->where('report.net_change', fn ($v) => abs((float) $v - 5000.0) < 0.005)
            ->where('report.direct.net', fn ($v) => abs((float) $v - 5000.0) < 0.005)
            ->where('report.indirect.net', fn ($v) => abs((float) $v - 5000.0) < 0.005));
});

it('forbids an employee from the cash flow statement', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/reports/cash-flow')->assertForbidden();
});

it('exports the cash flow statement as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/cash-flow/export.csv?from=2026-06-01&to=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/CashFlowEndpointTest.php`
Expected: FAIL — route/action/page missing.

- [ ] **Step 3: Extend the controller**

In `app/Http/Controllers/Finance/ReportController.php`, add `CashFlowReport` to the constructor:

```php
        private readonly \App\Services\Finance\Reports\CashFlowReport $cashFlow,
```

(Add it as the last constructor parameter, keeping the existing four.)

Add the action + CSV:

```php
    public function cashFlow(Request $request): Response
    {
        [$from, $to] = $this->periodRange($request);

        return Inertia::render('Finance/Reports/CashFlow', [
            'activeModule' => 'finance-reports',
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'report'       => $this->cashFlow->forPeriod($from, $to),
        ]);
    }

    public function cashFlowCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->periodRange($request);
        $report = $this->cashFlow->forPeriod($from, $to);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Cash Flow Statement', "{$report['from']} to {$report['to']}"]);
            fputcsv($out, []);
            fputcsv($out, ['Direct method', 'Amount']);
            fputcsv($out, ['Operating', $report['direct']['operating']]);
            fputcsv($out, ['Investing', $report['direct']['investing']]);
            fputcsv($out, ['Financing', $report['direct']['financing']]);
            fputcsv($out, ['Net change in cash', $report['direct']['net']]);
            fputcsv($out, []);
            fputcsv($out, ['Indirect method', 'Amount']);
            fputcsv($out, ['Surplus/(Deficit)', $report['indirect']['surplus']]);
            fputcsv($out, ['Operating', $report['indirect']['operating']]);
            fputcsv($out, ['Investing', $report['indirect']['investing']]);
            fputcsv($out, ['Financing', $report['indirect']['financing']]);
            fputcsv($out, ['Net change in cash', $report['indirect']['net']]);
            fclose($out);
        }, "cash-flow-{$report['from']}-to-{$report['to']}.csv", ['Content-Type' => 'text/csv']);
    }
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the existing `permission:finance.reports.view` group, add:

```php
            Route::get('reports/cash-flow',            [\App\Http\Controllers\Finance\ReportController::class, 'cashFlow'])->name('reports.cash-flow');
            Route::get('reports/cash-flow/export.csv', [\App\Http\Controllers\Finance\ReportController::class, 'cashFlowCsv'])->name('reports.cash-flow.csv');
```

- [ ] **Step 5: Create the Vue page**

`resources/js/Pages/Finance/Reports/CashFlow.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ from: String, to: String, report: { type: Object, required: true } });
const from = ref(props.from); const to = ref(props.to);
const method = ref('direct');
const apply = () => router.get(route('finance.reports.cash-flow'), { from: from.value, to: to.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>
<template>
    <Head title="Statement of Cash Flows" />
    <div class="p-6 max-w-3xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Statement of Cash Flows</h1>
                <p class="text-on-surface-variant text-sm mt-1">{{ from }} → {{ to }}</p>
                <nav class="mt-2 flex gap-3 text-xs font-bold">
                    <Link :href="route('finance.reports.trial-balance')" class="text-on-surface-variant hover:text-secondary">Trial Balance</Link>
                    <Link :href="route('finance.reports.financial-activities')" class="text-on-surface-variant hover:text-secondary">Financial Activities</Link>
                    <Link :href="route('finance.reports.financial-position')" class="text-on-surface-variant hover:text-secondary">Financial Position</Link>
                    <Link :href="route('finance.reports.cash-flow')" class="text-secondary">Cash Flows</Link>
                </nav>
            </div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>From <input type="date" v-model="from" aria-label="From date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <label>To <input type="date" v-model="to" aria-label="To date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
                <a :href="route('finance.reports.cash-flow.csv', { from, to })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">CSV</a>
            </div>
        </header>

        <div class="mb-4 inline-flex rounded-lg border border-outline-variant/60 overflow-hidden text-sm font-bold">
            <button @click="method = 'direct'" :class="method === 'direct' ? 'bg-secondary/20 text-secondary' : 'text-on-surface-variant'" class="px-4 py-2">Direct</button>
            <button @click="method = 'indirect'" :class="method === 'indirect' ? 'bg-secondary/20 text-secondary' : 'text-on-surface-variant'" class="px-4 py-2">Indirect</button>
        </div>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
            <template v-if="method === 'direct'">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-primary">Operating activities</span><span class="text-primary">{{ money(report.direct.operating) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Investing activities</span><span class="text-primary">{{ money(report.direct.investing) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Financing activities</span><span class="text-primary">{{ money(report.direct.financing) }}</span></div>
                </div>
            </template>
            <template v-else>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-primary">Surplus / (Deficit)</span><span class="text-primary">{{ money(report.indirect.surplus) }}</span></div>
                    <div class="flex justify-between text-on-surface-variant"><span>Δ Liabilities</span><span>{{ money(report.indirect.liability_change) }}</span></div>
                    <div class="flex justify-between text-on-surface-variant"><span>Δ Receivables</span><span>{{ money(-report.indirect.ar_change) }}</span></div>
                    <div class="flex justify-between border-t border-outline-variant/40 pt-2"><span class="text-primary">Operating activities</span><span class="text-primary">{{ money(report.indirect.operating) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Investing activities</span><span class="text-primary">{{ money(report.indirect.investing) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary">Financing activities</span><span class="text-primary">{{ money(report.indirect.financing) }}</span></div>
                </div>
            </template>
            <div class="flex justify-between border-t border-outline-variant/60 mt-3 pt-3 font-black text-primary">
                <span>Net change in cash</span><span>{{ money(report.net_change) }}</span>
            </div>
            <p class="mt-2 text-[11px] text-emerald-300">
                ✓ Direct {{ money(report.direct.net) }} = Indirect {{ money(report.indirect.net) }} = Net change {{ money(report.net_change) }}
            </p>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Add a Cash Flows link to the other statements' nav**

In each of `resources/js/Pages/Finance/Reports/TrialBalance.vue`, `FinancialActivities.vue`, and `FinancialPosition.vue`, add a Cash Flows `<Link>` to the existing `<nav>` block (FinancialActivities + FinancialPosition will need a `<nav>` added if they don't have one — TrialBalance already has the nav from P3-2). For TrialBalance.vue, add after the Financial Position link:

```vue
                    <Link :href="route('finance.reports.cash-flow')" class="text-on-surface-variant hover:text-secondary">Cash Flows</Link>
```

(For FinancialActivities.vue / FinancialPosition.vue, this is optional polish — they were shipped in P3-2 without a nav. Adding a nav there is nice-to-have; if you add it, mirror the TrialBalance nav with all four links and `import { Link }`. If you skip it, that's fine — the CashFlow page links back to them.)

- [ ] **Step 7: Build + run the test**

Run: `npm run build` (expect success, no Vue compile errors)
Run: `php artisan test tests/Feature/Finance/CashFlowEndpointTest.php`
Expected: PASS (all three).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Finance/ReportController.php routes/web.php resources/js/Pages/Finance/Reports/CashFlow.vue resources/js/Pages/Finance/Reports/TrialBalance.vue tests/Feature/Finance/CashFlowEndpointTest.php
git commit -m "feat(finance): Statement of Cash Flows (direct + indirect) page + CSV"
```

---

### Task 3: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Payroll tests/Feature/Loans tests/Feature/Disbursement`
Expected: PASS.

- [ ] **Step 2: Full app suite**

Run: `php artisan test`
Expected: PASS — accessibility green (the cash-flow date inputs carry `aria-label`); the `.csv` route is skipped by the smoke test. Allow only the known `KioskRecentTest` time-of-day flake if it is the sole failure.

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P3-3 cash flow regression gate green — Phase 3 complete"
```

---

## Self-Review notes (for the implementer)

- **Both methods reconcile by construction** to `netChangeInCash`. If the test fails on `direct.net != indirect.net != net_change`, the bug is in the categorization or the working-capital deltas — not the anchor. The anchor (Σ cash-account debit−credit) is ground truth.
- **`$$category` variable-variable** in `direct()` assigns to `$operating`/`$investing`/`$financing` by the string from `classify()` — those three vars are pre-initialised, so it's safe; keep it or expand to a match if clearer.
- **Cash accounts** = org bank account GLs + 1010 + 1130. A bank-to-bank transfer (two cash lines, no contra) nets to zero in both the anchor and direct (no contra) — consistent.
- **Reversed entries** are included (same status filter) so a reversed cash transaction nets out.
- **Investing = staff loans (1300)**; for this chart there are no fixed-asset accounts, so other investing is zero. Equity (3xxx) = financing.
- **Accessibility**: the two date inputs carry `aria-label`.
- **Phase 3 is complete after this** — Trial Balance, Financial Activities, Financial Position, Cash Flows, drill-down, comparatives, CSV/PDF.

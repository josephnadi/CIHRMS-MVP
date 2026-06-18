# Finance Phase 3 — P3-1: Ledger Balance Engine + Trial Balance

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the on-the-fly ledger balance engine (`LedgerBalanceService`) that aggregates posted journal lines by account over any date window, and ship the first financial statement — the **Trial Balance** — proving the engine via the Σ debits = Σ credits integrity invariant, with a report page and CSV + PDF export that establish the pattern for P3-2/P3-3.

**Architecture:** `LedgerBalanceService` runs one grouped SQL query over `journal_lines ⨝ journal_entries` (status=posted) and applies the natural-balance sign by account type. `TrialBalanceReport` groups its output into debit/credit columns. A read-only `ReportController` renders an Inertia page and streams CSV / renders dompdf PDF. New permission `finance.reports.view`.

**Tech Stack:** Laravel 13, Inertia + Vue 3, `barryvdh/laravel-dompdf`, Pest.

**This is P3-1 of Phase 3.** P3-2 (Financial Activities + Financial Position + comparatives + drill-down) and P3-3 (Cash Flow) reuse this engine + report scaffolding.

**Spec:** `docs/superpowers/specs/2026-06-18-finance-financial-statements-design.md`

---

### Task 1: LedgerBalanceService (the engine)

**Files:**
- Create: `app/Services/Finance/LedgerBalanceService.php`
- Test: `tests/Feature/Finance/LedgerBalanceServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

/** Create a posted (or draft) balanced 2-line entry: DR $drCode / CR $crCode for $amount. */
function ledgerEntry(string $drCode, string $crCode, float $amount, string $date, string $status = 'posted'): JournalEntry
{
    $dr = GlAccount::where('code', $drCode)->firstOrFail();
    $cr = GlAccount::where('code', $crCode)->firstOrFail();
    $je = JournalEntry::create([
        'reference'   => 'JE-LB-' . uniqid(),
        'entry_date'  => $date,
        'narration'   => 'ledger test',
        'status'      => $status,
        'source_type' => JournalSourceType::Manual->value,
        'source_id'   => null,
        'created_by'  => \App\Models\User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $dr->id, 'debit_amount' => $amount, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cr->id, 'debit_amount' => 0, 'credit_amount' => $amount]);
    return $je;
}

it('aggregates posted lines as-of a date with natural balances', function () {
    ledgerEntry('5100', '2300', 1000.0, '2026-06-15'); // DR Salaries Expense / CR Salaries Payable

    $rows = app(LedgerBalanceService::class)->asOf(CarbonImmutable::create(2026, 6, 30))->keyBy('code');

    expect((float) $rows['5100']->debit_total)->toBe(1000.0)
        ->and((float) $rows['5100']->natural_balance)->toBe(1000.0)   // expense: debit - credit
        ->and((float) $rows['2300']->credit_total)->toBe(1000.0)
        ->and((float) $rows['2300']->natural_balance)->toBe(1000.0);  // liability: credit - debit
});

it('excludes draft and is date-bounded', function () {
    ledgerEntry('5100', '2300', 1000.0, '2026-06-15', status: 'posted');
    ledgerEntry('5100', '2300', 500.0, '2026-06-15', status: 'draft'); // excluded

    $svc = app(LedgerBalanceService::class);

    // as-of after the entry: only the posted 1000 counts
    expect((float) $svc->asOf(CarbonImmutable::create(2026, 6, 30))->firstWhere('code', '5100')->natural_balance)->toBe(1000.0);

    // as-of before the entry: nothing
    expect($svc->asOf(CarbonImmutable::create(2026, 5, 31))->firstWhere('code', '5100'))->toBeNull();

    // period activity in July: nothing (entry is in June)
    expect($svc->activity(CarbonImmutable::create(2026, 7, 1), CarbonImmutable::create(2026, 7, 31))->firstWhere('code', '5100'))->toBeNull();

    // period activity in June: the 1000
    expect((float) $svc->activity(CarbonImmutable::create(2026, 6, 1), CarbonImmutable::create(2026, 6, 30))->firstWhere('code', '5100')->natural_balance)->toBe(1000.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/LedgerBalanceServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalEntryStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * On-the-fly ledger balances. Aggregates POSTED journal_lines by account over a
 * date window and applies the natural-balance sign by account type
 * (asset/expense = debit - credit; liability/equity/income = credit - debit).
 * Every financial statement is a presentation of this one method.
 */
class LedgerBalanceService
{
    /**
     * One row per account with posted activity in the window.
     *
     * @return Collection<int, object{account_id:int, code:string, name:string, type:string, debit_total:float, credit_total:float, natural_balance:float}>
     */
    public function balances(?CarbonInterface $from, CarbonInterface $to): Collection
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('gl_accounts as ga', 'ga.id', '=', 'jl.gl_account_id')
            ->where('je.status', JournalEntryStatus::Posted->value)
            ->whereDate('je.entry_date', '<=', $to->toDateString());

        if ($from !== null) {
            $query->whereDate('je.entry_date', '>=', $from->toDateString());
        }

        return $query
            ->groupBy('ga.id', 'ga.code', 'ga.name', 'ga.type')
            ->selectRaw('ga.id as account_id, ga.code, ga.name, ga.type, SUM(jl.debit_amount) as debit_total, SUM(jl.credit_amount) as credit_total')
            ->orderBy('ga.code')
            ->get()
            ->map(function ($r) {
                $debit  = (float) $r->debit_total;
                $credit = (float) $r->credit_total;

                return (object) [
                    'account_id'      => (int) $r->account_id,
                    'code'            => $r->code,
                    'name'            => $r->name,
                    'type'            => $r->type,
                    'debit_total'     => round($debit, 2),
                    'credit_total'    => round($credit, 2),
                    'natural_balance' => $this->naturalBalance($r->type, $debit, $credit),
                ];
            });
    }

    /** Cumulative balances at a point in time. */
    public function asOf(CarbonInterface $date): Collection
    {
        return $this->balances(null, $date);
    }

    /** Period activity (flow) within a date range, inclusive. */
    public function activity(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->balances($from, $to);
    }

    private function naturalBalance(string $type, float $debit, float $credit): float
    {
        return in_array($type, ['asset', 'expense'], true)
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/LedgerBalanceServiceTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/LedgerBalanceService.php tests/Feature/Finance/LedgerBalanceServiceTest.php
git commit -m "feat(finance): LedgerBalanceService (on-the-fly ledger balance engine)"
```

---

### Task 2: TrialBalanceReport (presenter)

**Files:**
- Create: `app/Services/Finance/Reports/TrialBalanceReport.php`
- Test: `tests/Feature/Finance/TrialBalanceReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\TrialBalanceReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function postEntry(array $lines, string $date = '2026-06-15'): void
{
    // $lines: array of [code, debit, credit]
    $je = JournalEntry::create([
        'reference' => 'JE-TB-' . uniqid(), 'entry_date' => $date, 'narration' => 'tb',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    $no = 1;
    foreach ($lines as [$code, $debit, $credit]) {
        JournalLine::create([
            'journal_entry_id' => $je->id, 'line_no' => $no++,
            'gl_account_id' => GlAccount::where('code', $code)->value('id'),
            'debit_amount' => $debit, 'credit_amount' => $credit,
        ]);
    }
}

it('produces a balanced trial balance (debits = credits)', function () {
    // Two balanced entries.
    postEntry([['5100', 1000, 0], ['2300', 0, 1000]]); // expense / liability
    postEntry([['1100', 2000, 0], ['4100', 0, 2000]]); // asset / income

    $report = app(TrialBalanceReport::class)->forDate(CarbonImmutable::create(2026, 6, 30));

    expect($report['balanced'])->toBeTrue()
        ->and($report['total_debit'])->toBe($report['total_credit'])
        ->and($report['total_debit'])->toBe(3000.0); // 1000 expense + 2000 asset on the debit side

    $byCode = collect($report['rows'])->keyBy('code');
    expect($byCode['5100']['debit'])->toBe(1000.0)->and($byCode['5100']['credit'])->toBe(0.0)
        ->and($byCode['2300']['credit'])->toBe(1000.0)->and($byCode['2300']['debit'])->toBe(0.0)
        ->and($byCode['4100']['credit'])->toBe(2000.0);
});

it('returns an empty, balanced report when there are no postings', function () {
    $report = app(TrialBalanceReport::class)->forDate(CarbonImmutable::create(2026, 6, 30));
    expect($report['rows'])->toBe([])
        ->and($report['total_debit'])->toBe(0.0)
        ->and($report['total_credit'])->toBe(0.0)
        ->and($report['balanced'])->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/TrialBalanceReportTest.php`
Expected: FAIL — presenter missing.

- [ ] **Step 3: Write the presenter**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance\Reports;

use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonInterface;

/**
 * Trial Balance: each account's natural balance placed in its debit or credit
 * column by type. Σ debit column = Σ credit column (the integrity proof) because
 * every posted entry is itself balanced.
 */
class TrialBalanceReport
{
    public function __construct(private readonly LedgerBalanceService $ledger)
    {
    }

    /** @return array{as_of:string, rows:array<int,array{code:string,name:string,type:string,debit:float,credit:float}>, total_debit:float, total_credit:float, balanced:bool} */
    public function forDate(CarbonInterface $date): array
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $rows = [];

        foreach ($this->ledger->asOf($date) as $b) {
            $isDebitSide = in_array($b->type, ['asset', 'expense'], true);
            $debit  = $isDebitSide ? $b->natural_balance : 0.0;
            $credit = $isDebitSide ? 0.0 : $b->natural_balance;

            $totalDebit  += $debit;
            $totalCredit += $credit;

            $rows[] = [
                'code'   => $b->code,
                'name'   => $b->name,
                'type'   => $b->type,
                'debit'  => round($debit, 2),
                'credit' => round($credit, 2),
            ];
        }

        $totalDebit  = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);

        return [
            'as_of'        => $date->toDateString(),
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced'     => abs($totalDebit - $totalCredit) < 0.005,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/TrialBalanceReportTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/Reports/TrialBalanceReport.php tests/Feature/Finance/TrialBalanceReportTest.php
git commit -m "feat(finance): TrialBalanceReport presenter"
```

---

### Task 3: finance.reports.view permission

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Test: `tests/Feature/Finance/ReportsPermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants finance.reports.view to finance_officer and auditor, not employee', function () {
    expect(User::factory()->create(['role' => 'finance_officer'])->hasPermission('finance.reports.view'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'auditor'])->hasPermission('finance.reports.view'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('finance.reports.view'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/ReportsPermissionTest.php`
Expected: FAIL — permission absent.

- [ ] **Step 3: Declare + grant the permission**

In `database/seeders/RolePermissionSeeder.php`, inside `private const PERMISSIONS`, add (after the `finance.period.*` entries):

```php
        'finance.reports.view'  => ['Finance', 'View financial statements (trial balance, P&L, balance sheet, cash flow)'],
```

In the `'finance_officer' => [` role array, add (after the `finance.period.*` grants):

```php
            'finance.reports.view',
```

In the `'auditor' => [` role array, add `'finance.reports.view',` (auditors need read access to statements — add it near the other finance read grants like `accounts.view`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/ReportsPermissionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php tests/Feature/Finance/ReportsPermissionTest.php
git commit -m "feat(finance): finance.reports.view permission"
```

---

### Task 4: ReportController — Trial Balance page + CSV export

**Files:**
- Create: `app/Http/Controllers/Finance/ReportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/TrialBalanceEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();

    $je = JournalEntry::create([
        'reference' => 'JE-EP-1', 'entry_date' => '2026-06-15', 'narration' => 'ep',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '5100')->value('id'), 'debit_amount' => 1000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '2300')->value('id'), 'debit_amount' => 0, 'credit_amount' => 1000]);
});

it('finance_officer can view the trial balance page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/trial-balance?as_of=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/TrialBalance')->where('report.balanced', true));
});

it('employee is forbidden from the trial balance', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/reports/trial-balance')->assertForbidden();
});

it('exports the trial balance as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/trial-balance/export.csv?as_of=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    $body = $res->streamedContent();
    expect($body)->toContain('5100')->toContain('1000');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/TrialBalanceEndpointTest.php`
Expected: FAIL — routes/controller missing.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\Reports\TrialBalanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly TrialBalanceReport $trialBalance)
    {
    }

    public function trialBalance(Request $request): Response
    {
        $asOf = $this->asOfDate($request);

        return Inertia::render('Finance/Reports/TrialBalance', [
            'activeModule' => 'finance-reports',
            'asOf'         => $asOf->toDateString(),
            'report'       => $this->trialBalance->forDate($asOf),
        ]);
    }

    public function trialBalanceCsv(Request $request): StreamedResponse
    {
        $asOf   = $this->asOfDate($request);
        $report = $this->trialBalance->forDate($asOf);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Code', 'Account', 'Type', 'Debit', 'Credit']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, [$row['code'], $row['name'], $row['type'], $row['debit'], $row['credit']]);
            }
            fputcsv($out, ['', 'TOTAL', '', $report['total_debit'], $report['total_credit']]);
            fclose($out);
        }, "trial-balance-{$report['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    private function asOfDate(Request $request): CarbonImmutable
    {
        $raw = $request->query('as_of');

        return $raw ? CarbonImmutable::parse($raw) : CarbonImmutable::today();
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `Route::prefix('finance')->name('finance.')->group(...)` block, add (e.g. after the periods group):

```php
        // Financial statements (Phase 3) — read-only
        Route::middleware('permission:finance.reports.view')->group(function () {
            Route::get('reports/trial-balance',            [\App\Http\Controllers\Finance\ReportController::class, 'trialBalance'])->name('reports.trial-balance');
            Route::get('reports/trial-balance/export.csv', [\App\Http\Controllers\Finance\ReportController::class, 'trialBalanceCsv'])->name('reports.trial-balance.csv');
        });
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/TrialBalanceEndpointTest.php`
Expected: FAIL on the first case — the Vue page `Finance/Reports/TrialBalance` doesn't exist yet (Inertia's component-exists check). Create the Vue page in Task 5, then this passes. The CSV + forbidden cases should already pass. (If you prefer strict ordering, create a minimal stub page now and flesh it out in Task 5 — Inertia only needs the file to exist.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Finance/ReportController.php routes/web.php tests/Feature/Finance/TrialBalanceEndpointTest.php
git commit -m "feat(finance): trial balance report page + CSV export endpoints"
```

---

### Task 5: Trial Balance Vue page + Reports hub link

**Files:**
- Create: `resources/js/Pages/Finance/Reports/TrialBalance.vue`
- Modify: `resources/js/Pages/Finance/Hub.vue`

- [ ] **Step 1: Study the design tokens** — read `resources/js/Pages/Finance/FiscalCalendar/Index.vue` for the project's Material-style tokens, `AuthenticatedLayout` via `defineOptions`, and `router.get` usage. The `<select>`/date input must have a label or `aria-label` (the `AccessibilityAuditorTest` fails on unlabeled form controls).

- [ ] **Step 2: Write the page**

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    asOf:   { type: String, required: true },
    report: { type: Object, required: true },
});

const asOf = ref(props.asOf);
const apply = () => router.get(route('finance.reports.trial-balance'), { as_of: asOf.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const csvHref = computed(() => route('finance.reports.trial-balance.csv', { as_of: props.asOf }));
</script>

<template>
    <Head title="Trial Balance" />

    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Trial Balance</h1>
                <p class="text-on-surface-variant text-sm mt-1">As of {{ asOf }}</p>
            </div>
            <div class="flex items-end gap-3">
                <label class="text-xs font-bold text-on-surface-variant">As of
                    <input type="date" v-model="asOf" aria-label="As-of date"
                           class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm font-bold text-secondary">Apply</button>
                <a :href="csvHref" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm font-bold text-primary">CSV</a>
            </div>
        </header>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[11px] uppercase tracking-wide border-b border-outline-variant/40">
                    <tr>
                        <th class="text-left p-3">Code</th>
                        <th class="text-left p-3">Account</th>
                        <th class="text-right p-3">Debit</th>
                        <th class="text-right p-3">Credit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="row in report.rows" :key="row.code">
                        <td class="p-3 font-mono text-on-surface-variant">{{ row.code }}</td>
                        <td class="p-3 text-primary">{{ row.name }}</td>
                        <td class="p-3 text-right text-primary">{{ row.debit ? money(row.debit) : '' }}</td>
                        <td class="p-3 text-right text-primary">{{ row.credit ? money(row.credit) : '' }}</td>
                    </tr>
                    <tr v-if="report.rows.length === 0">
                        <td colspan="4" class="p-6 text-center text-on-surface-variant">No postings as of this date.</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-outline-variant/60 font-black">
                    <tr>
                        <td class="p-3" colspan="2">Total</td>
                        <td class="p-3 text-right" :class="report.balanced ? 'text-primary' : 'text-amber-300'">{{ money(report.total_debit) }}</td>
                        <td class="p-3 text-right" :class="report.balanced ? 'text-primary' : 'text-amber-300'">{{ money(report.total_credit) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p v-if="!report.balanced" class="mt-3 text-amber-300 text-sm font-bold">
            ⚠ Trial balance is out of balance — investigate the ledger.
        </p>
    </div>
</template>
```

- [ ] **Step 3: Add a Reports link to the Finance Hub**

In `resources/js/Pages/Finance/Hub.vue`, inside the `<div class="flex gap-2">` header block (after the Fiscal Calendar link), add:

```vue
                <Link :href="route('finance.reports.trial-balance')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">summarize</span>
                    Reports
                </Link>
```

- [ ] **Step 4: Build + run the endpoint test**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

Run: `php artisan test tests/Feature/Finance/TrialBalanceEndpointTest.php`
Expected: PASS (all three now that the page exists).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Finance/Reports/TrialBalance.vue resources/js/Pages/Finance/Hub.vue
git commit -m "feat(finance): Trial Balance report UI + Reports hub link"
```

---

### Task 6: PDF export (dompdf)

**Files:**
- Create: `resources/views/finance/reports/trial-balance-pdf.blade.php`
- Modify: `app/Http/Controllers/Finance/ReportController.php`
- Modify: `routes/web.php`
- Test: extend `tests/Feature/Finance/TrialBalanceEndpointTest.php`

- [ ] **Step 1: Add the failing test case**

Append to `tests/Feature/Finance/TrialBalanceEndpointTest.php`:

```php
it('exports the trial balance as PDF', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/trial-balance/export.pdf?as_of=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Finance/TrialBalanceEndpointTest.php`
Expected: FAIL — pdf route missing (404).

- [ ] **Step 3: Write the Blade PDF template**

`resources/views/finance/reports/trial-balance-pdf.blade.php`:

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 16px; margin: 0 0 2px; } .sub { color: #666; margin: 0 0 12px; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
th { text-align: left; background: #f3f3f3; }
.r { text-align: right; } tfoot td { font-weight: bold; border-top: 2px solid #333; }
</style></head>
<body>
    <h1>Trial Balance</h1>
    <p class="sub">As of {{ $report['as_of'] }}</p>
    <table>
        <thead><tr><th>Code</th><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
        <tbody>
        @foreach ($report['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td>
                <td class="r">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                <td class="r">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="2">Total</td>
            <td class="r">{{ number_format($report['total_debit'], 2) }}</td>
            <td class="r">{{ number_format($report['total_credit'], 2) }}</td>
        </tr></tfoot>
    </table>
</body>
</html>
```

- [ ] **Step 4: Add the controller action + import**

In `app/Http/Controllers/Finance/ReportController.php`, add the import:

```php
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
```

Add the action:

```php
    public function trialBalancePdf(Request $request): HttpResponse
    {
        $asOf   = $this->asOfDate($request);
        $report = $this->trialBalance->forDate($asOf);

        return Pdf::loadView('finance.reports.trial-balance-pdf', ['report' => $report])
            ->download("trial-balance-{$report['as_of']}.pdf");
    }
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the `finance.reports.view` group added in Task 4, add:

```php
            Route::get('reports/trial-balance/export.pdf', [\App\Http\Controllers\Finance\ReportController::class, 'trialBalancePdf'])->name('reports.trial-balance.pdf');
```

- [ ] **Step 6: Add a PDF link in the Vue page**

In `resources/js/Pages/Finance/Reports/TrialBalance.vue`, after the CSV `<a>`, add a PDF link:

```vue
                <a :href="route('finance.reports.trial-balance.pdf', { as_of: props.asOf })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm font-bold text-primary">PDF</a>
```

(Add `const pdfHref = ...` is unnecessary — use the inline `route(...)` like the CSV link, or mirror the `csvHref` computed with a `pdfHref` computed if you prefer consistency.)

- [ ] **Step 7: Build + run the test**

Run: `npm run build` (expect success)
Run: `php artisan test tests/Feature/Finance/TrialBalanceEndpointTest.php`
Expected: PASS (now 4 cases incl. PDF).

- [ ] **Step 8: Commit**

```bash
git add resources/views/finance/reports/trial-balance-pdf.blade.php app/Http/Controllers/Finance/ReportController.php routes/web.php resources/js/Pages/Finance/Reports/TrialBalance.vue tests/Feature/Finance/TrialBalanceEndpointTest.php
git commit -m "feat(finance): trial balance PDF export (dompdf)"
```

---

### Task 7: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Payroll tests/Feature/Loans tests/Feature/Disbursement`
Expected: PASS.

- [ ] **Step 2: Full app suite (incl. accessibility auditor)**

Run: `php artisan test`
Expected: PASS — confirm `AccessibilityAuditorTest` is green (the date input + select carry `aria-label`). Allow the known time-of-day `KioskRecentTest` flake only if it is the sole failure.

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P3-1 ledger engine + trial balance regression gate green"
```

---

## Self-Review notes (for the implementer)

- **The engine is the whole subsystem's foundation** — `balances()` is the only place that touches the ledger tables; P3-2/P3-3 statements call `asOf`/`activity` and group. Keep it correct and simple.
- **Trial balance ties by construction**: Σ(debit-side natural balances) = Σ(credit-side natural balances) because Σ(all debits) = Σ(all credits) over balanced posted entries. The `balanced` flag is the integrity proof; tests assert it.
- **Posted-only**: drafts and the *original* of a reversed pair are excluded by `status = posted`; a reversal's own posted entry nets the original out, so balances are correct.
- **Inertia component-exists ordering**: the page render test needs the Vue file on disk — create it (Task 5) before expecting the render test green, or stub it. The CSV/PDF/forbidden cases don't need the page.
- **Accessibility**: every form control (the as-of date input) needs a label/`aria-label`, or `AccessibilityAuditorTest` fails (as it did in P2-2).
- **dompdf** (`barryvdh/laravel-dompdf`) is already installed — `Pdf::loadView(...)->download(...)` needs no extra setup.

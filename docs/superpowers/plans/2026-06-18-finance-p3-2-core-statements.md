# Finance Phase 3 — P3-2: Financial Activities + Financial Position + Drill-down

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the two core financial statements — **Statement of Financial Activities** (Income & Expenditure → Surplus/Deficit, with a prior-period comparative) and **Statement of Financial Position** (Balance Sheet, with the Assets = Liabilities + Funds integrity check and a prior-year comparative) — plus **GL drill-down** (the posted lines composing any account's balance), reusing the P3-1 `LedgerBalanceService` and report scaffolding.

**Architecture:** Two presenters over `LedgerBalanceService`: `IncomeExpenditureReport::forPeriod(from,to)` groups income (4xxx) − expenditure (5xxx) into surplus, current vs the immediately-preceding equal-length period; `FinancialPositionReport::asOf(date)` groups assets/liabilities/equity with cumulative surplus folded into funds (the equation holds by construction), current vs one year prior. A drill-down adds `LedgerBalanceService::accountLines()` and a page listing an account's posted lines. `ReportController` gains actions; each statement gets a Vue page + CSV/PDF export.

**Tech Stack:** Laravel 13, Inertia + Vue 3, dompdf, Pest.

**This is P3-2 of Phase 3.** P3-3 (Cash Flow) follows.

**Spec:** `docs/superpowers/specs/2026-06-18-finance-financial-statements-design.md`

## Integrity invariants (the tests)

- **Statement of Financial Activities**: Surplus = Income − Expenditure (period activity).
- **Statement of Financial Position**: Assets = Liabilities + Equity + Surplus, where Surplus = cumulative Income − Expenditure as-of the date. Holds for any balanced posted+reversed ledger (every entry balances), so the report's `balanced` flag must be true on real data.

---

### Task 1: Comparative period helpers on LedgerBalanceService + accountLines

Add the comparative-window helper and the drill-down line query the presenters/drill-down need.

**Files:**
- Modify: `app/Services/Finance/LedgerBalanceService.php`
- Test: `tests/Feature/Finance/LedgerBalanceAccountLinesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('lists the posted lines for an account within a window', function () {
    $exp = GlAccount::where('code', '5100')->firstOrFail();
    $pay = GlAccount::where('code', '2300')->firstOrFail();

    $je = JournalEntry::create([
        'reference' => 'JE-AL-1', 'entry_date' => '2026-06-15', 'narration' => 'salaries',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $exp->id, 'debit_amount' => 1000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $pay->id, 'debit_amount' => 0, 'credit_amount' => 1000]);

    $lines = app(LedgerBalanceService::class)->accountLines($exp->id, null, CarbonImmutable::create(2026, 6, 30));

    expect($lines)->toHaveCount(1);
    expect($lines->first()->reference)->toBe('JE-AL-1')
        ->and((float) $lines->first()->debit)->toBe(1000.0)
        ->and($lines->first()->narration)->toBe('salaries');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/LedgerBalanceAccountLinesTest.php`
Expected: FAIL — `accountLines` missing.

- [ ] **Step 3: Add the methods**

In `app/Services/Finance/LedgerBalanceService.php`, add these public methods (the `CarbonImmutable`/`Collection`/`DB`/`JournalEntryStatus` imports already exist; add `use Carbon\CarbonImmutable;` if not present):

```php
    /**
     * Individual posted/reversed lines for one account in a window (GL drill-down).
     *
     * @return Collection<int, object{entry_id:int, reference:string, entry_date:string, narration:?string, debit:float, credit:float}>
     */
    public function accountLines(int $accountId, ?CarbonInterface $from, CarbonInterface $to): Collection
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.gl_account_id', $accountId)
            ->whereIn('je.status', [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value])
            ->whereDate('je.entry_date', '<=', $to->toDateString());

        if ($from !== null) {
            $query->whereDate('je.entry_date', '>=', $from->toDateString());
        }

        return $query
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jl.line_no')
            ->get(['je.id as entry_id', 'je.reference', 'je.entry_date', 'je.narration', 'jl.debit_amount', 'jl.credit_amount'])
            ->map(fn ($r) => (object) [
                'entry_id'   => (int) $r->entry_id,
                'reference'  => $r->reference,
                'entry_date' => (string) $r->entry_date,
                'narration'  => $r->narration,
                'debit'      => round((float) $r->debit_amount, 2),
                'credit'     => round((float) $r->credit_amount, 2),
            ]);
    }

    /**
     * The equal-length period immediately preceding [$from, $to] — used for
     * comparative statement columns.
     *
     * @return array{0:CarbonImmutable, 1:CarbonImmutable} [priorFrom, priorTo]
     */
    public function priorPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $f = CarbonImmutable::parse($from->toDateString());
        $t = CarbonImmutable::parse($to->toDateString());
        $lengthDays = $f->diffInDays($t);
        $priorTo   = $f->subDay();
        $priorFrom = $priorTo->subDays($lengthDays);

        return [$priorFrom, $priorTo];
    }
```

(Add `use Carbon\CarbonImmutable;` to the imports if it isn't already there — `CarbonInterface` is.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/LedgerBalanceAccountLinesTest.php`
Expected: PASS.

- [ ] **Step 5: Confirm the existing engine tests still pass**

Run: `php artisan test tests/Feature/Finance/LedgerBalanceServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/LedgerBalanceService.php tests/Feature/Finance/LedgerBalanceAccountLinesTest.php
git commit -m "feat(finance): accountLines drill-down + priorPeriod helper on LedgerBalanceService"
```

---

### Task 2: IncomeExpenditureReport (Statement of Financial Activities)

**Files:**
- Create: `app/Services/Finance/Reports/IncomeExpenditureReport.php`
- Test: `tests/Feature/Finance/IncomeExpenditureReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\IncomeExpenditureReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function ie_post(string $drCode, string $crCode, float $amount, string $date): void
{
    $je = JournalEntry::create([
        'reference' => 'JE-IE-' . uniqid(), 'entry_date' => $date, 'narration' => 'ie',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', $drCode)->value('id'), 'debit_amount' => $amount, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', $crCode)->value('id'), 'debit_amount' => 0, 'credit_amount' => $amount]);
}

it('computes income minus expenditure as surplus for the period', function () {
    // June 2026: income 5000 (DR cash/CR membership), expense 3000 (DR salaries/CR payable)
    ie_post('1100', '4100', 5000, '2026-06-10'); // income
    ie_post('5100', '2300', 3000, '2026-06-12'); // expenditure

    $report = app(IncomeExpenditureReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($report['income']['total_current'])->toBe(5000.0)
        ->and($report['expenditure']['total_current'])->toBe(3000.0)
        ->and($report['surplus_current'])->toBe(2000.0);
});

it('includes a prior-period comparative', function () {
    ie_post('1100', '4100', 5000, '2026-06-10'); // current (June)
    ie_post('1100', '4100', 1000, '2026-05-10'); // prior (May)

    $report = app(IncomeExpenditureReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($report['income']['total_current'])->toBe(5000.0)
        ->and($report['income']['total_prior'])->toBe(1000.0)   // May, the preceding equal-length window
        ->and($report['surplus_prior'])->toBe(1000.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/IncomeExpenditureReportTest.php`
Expected: FAIL — presenter missing.

- [ ] **Step 3: Write the presenter**

```php
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

    /** @return array{from:string, to:string, income:array, expenditure:array, surplus_current:float, surplus_prior:float} */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $current = $this->ledger->activity($from, $to)->keyBy('code');
        [$priorFrom, $priorTo] = $this->ledger->priorPeriod($from, $to);
        $prior = $this->ledger->activity($priorFrom, $priorTo)->keyBy('code');

        $income      = $this->section($current, $prior, 'income');
        $expenditure = $this->section($current, $prior, 'expense');

        return [
            'from'            => $from->toDateString(),
            'to'              => $to->toDateString(),
            'income'          => $income,
            'expenditure'     => $expenditure,
            'surplus_current' => round($income['total_current'] - $expenditure['total_current'], 2),
            'surplus_prior'   => round($income['total_prior'] - $expenditure['total_prior'], 2),
        ];
    }

    /** Build a section (income or expense) with current + prior amounts per account. */
    private function section(Collection $current, Collection $prior, string $type): array
    {
        // union (not merge): GL codes are numeric strings; Collection::merge reindexes
        // numeric keys (zeroing the section), while union (+) preserves them.
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/IncomeExpenditureReportTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/Reports/IncomeExpenditureReport.php tests/Feature/Finance/IncomeExpenditureReportTest.php
git commit -m "feat(finance): IncomeExpenditureReport (Statement of Financial Activities)"
```

---

### Task 3: FinancialPositionReport (Statement of Financial Position)

**Files:**
- Create: `app/Services/Finance/Reports/FinancialPositionReport.php`
- Test: `tests/Feature/Finance/FinancialPositionReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\FinancialPositionReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function fp_post(array $lines, string $date): void
{
    $je = JournalEntry::create([
        'reference' => 'JE-FP-' . uniqid(), 'entry_date' => $date, 'narration' => 'fp',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    $no = 1;
    foreach ($lines as [$code, $debit, $credit]) {
        JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => $no++, 'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'debit_amount' => $debit, 'credit_amount' => $credit]);
    }
}

it('balances: assets = liabilities + equity + surplus', function () {
    // Receive 5000 membership income into bank, pay 2000 salaries from bank.
    fp_post([['1100', 5000, 0], ['4100', 0, 5000]], '2026-06-10'); // bank +5000, income 5000
    fp_post([['5100', 2000, 0], ['1100', 0, 2000]], '2026-06-12'); // expense 2000, bank -2000

    $report = app(FinancialPositionReport::class)->asOf(CarbonImmutable::create(2026, 6, 30));

    // Bank = 3000 (asset). Surplus = income 5000 - expense 2000 = 3000. No liabilities/equity.
    expect($report['assets']['total_current'])->toBe(3000.0)
        ->and($report['liabilities']['total_current'])->toBe(0.0)
        ->and($report['surplus_current'])->toBe(3000.0)
        ->and($report['total_funds_current'])->toBe(3000.0) // equity 0 + surplus 3000
        ->and($report['balanced_current'])->toBeTrue();      // assets 3000 = liab 0 + funds 3000
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/FinancialPositionReportTest.php`
Expected: FAIL — presenter missing.

- [ ] **Step 3: Write the presenter**

```php
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
        // union (not merge): GL codes are numeric strings; Collection::merge reindexes
        // numeric keys (zeroing the section), while union (+) preserves them.
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/FinancialPositionReportTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/Reports/FinancialPositionReport.php tests/Feature/Finance/FinancialPositionReportTest.php
git commit -m "feat(finance): FinancialPositionReport (Statement of Financial Position)"
```

---

### Task 4: Controller actions + routes (statements + drill-down + CSV)

**Files:**
- Modify: `app/Http/Controllers/Finance/ReportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/CoreStatementsEndpointTest.php`

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
        'reference' => 'JE-CS-1', 'entry_date' => '2026-06-10', 'narration' => 'cs',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 5000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '4100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 5000]);
});

it('renders the financial activities statement', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/financial-activities?from=2026-06-01&to=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/FinancialActivities')->where('report.surplus_current', 5000.0));
});

it('renders the financial position statement (balanced)', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/financial-position?as_of=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/FinancialPosition')->where('report.balanced_current', true));
});

it('renders the account ledger drill-down', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $bank = GlAccount::where('code', '1100')->firstOrFail();
    $this->actingAs($u)->get("/finance/reports/account/{$bank->id}/ledger?to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/AccountLedger')->has('lines', 1));
});

it('forbids an employee from the statements', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/reports/financial-position')->assertForbidden();
});

it('exports financial activities as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/financial-activities/export.csv?from=2026-06-01&to=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/CoreStatementsEndpointTest.php`
Expected: FAIL — routes/actions/pages missing.

- [ ] **Step 3: Extend the controller**

In `app/Http/Controllers/Finance/ReportController.php`:

(a) Add constructor deps + imports. Change the constructor to inject the two new presenters + the ledger:

```php
    public function __construct(
        private readonly \App\Services\Finance\Reports\TrialBalanceReport $trialBalance,
        private readonly \App\Services\Finance\Reports\IncomeExpenditureReport $incomeExpenditure,
        private readonly \App\Services\Finance\Reports\FinancialPositionReport $financialPosition,
        private readonly \App\Services\Finance\LedgerBalanceService $ledger,
    ) {
    }
```

Add this import near the top (it is the only new import needed; `Request`, `CarbonImmutable`, `Inertia`, `Response`, `StreamedResponse` are already imported from P3-1):

```php
use App\Models\GlAccount;
```

(b) Add a period helper next to `asOfDate()`:

```php
    /** @return array{0:CarbonImmutable,1:CarbonImmutable} [from, to] */
    private function periodRange(Request $request): array
    {
        $to   = $this->safeParse($request->query('to')) ?? CarbonImmutable::today();
        $from = $this->safeParse($request->query('from')) ?? $to->startOfMonth();

        return [$from, $to];
    }

    private function safeParse(?string $raw): ?CarbonImmutable
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
```

And simplify `asOfDate()` to reuse `safeParse`:

```php
    private function asOfDate(Request $request): CarbonImmutable
    {
        return $this->safeParse($request->query('as_of')) ?? CarbonImmutable::today();
    }
```

(c) Add the three render actions + the CSV export:

```php
    public function financialActivities(Request $request): Response
    {
        [$from, $to] = $this->periodRange($request);

        return Inertia::render('Finance/Reports/FinancialActivities', [
            'activeModule' => 'finance-reports',
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'report'       => $this->incomeExpenditure->forPeriod($from, $to),
        ]);
    }

    public function financialPosition(Request $request): Response
    {
        $asOf = $this->asOfDate($request);

        return Inertia::render('Finance/Reports/FinancialPosition', [
            'activeModule' => 'finance-reports',
            'asOf'         => $asOf->toDateString(),
            'report'       => $this->financialPosition->asOf($asOf),
        ]);
    }

    public function accountLedger(Request $request, GlAccount $account): Response
    {
        $to   = $this->safeParse($request->query('to')) ?? CarbonImmutable::today();
        $from = $this->safeParse($request->query('from'));

        return Inertia::render('Finance/Reports/AccountLedger', [
            'activeModule' => 'finance-reports',
            'account'      => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'from'         => $from?->toDateString(),
            'to'           => $to->toDateString(),
            'lines'        => $this->ledger->accountLines($account->id, $from, $to),
        ]);
    }

    public function financialActivitiesCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->periodRange($request);
        $report = $this->incomeExpenditure->forPeriod($from, $to);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Section', 'Code', 'Account', 'Current', 'Prior']);
            foreach (['income' => 'Income', 'expenditure' => 'Expenditure'] as $key => $label) {
                foreach ($report[$key]['rows'] as $row) {
                    fputcsv($out, [$label, $row['code'], $row['name'], $row['current'], $row['prior']]);
                }
                fputcsv($out, [$label . ' total', '', '', $report[$key]['total_current'], $report[$key]['total_prior']]);
            }
            fputcsv($out, ['Surplus/(Deficit)', '', '', $report['surplus_current'], $report['surplus_prior']]);
            fclose($out);
        }, "financial-activities-{$report['from']}-to-{$report['to']}.csv", ['Content-Type' => 'text/csv']);
    }
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the existing `Route::middleware('permission:finance.reports.view')->group(...)` (added in P3-1), add:

```php
            Route::get('reports/financial-activities',             [\App\Http\Controllers\Finance\ReportController::class, 'financialActivities'])->name('reports.financial-activities');
            Route::get('reports/financial-activities/export.csv',  [\App\Http\Controllers\Finance\ReportController::class, 'financialActivitiesCsv'])->name('reports.financial-activities.csv');
            Route::get('reports/financial-position',               [\App\Http\Controllers\Finance\ReportController::class, 'financialPosition'])->name('reports.financial-position');
            Route::get('reports/account/{account}/ledger',         [\App\Http\Controllers\Finance\ReportController::class, 'accountLedger'])->name('reports.account-ledger');
```

(The `{account}` binds `App\Models\GlAccount` by the `account` parameter name.)

- [ ] **Step 5: Create the three Vue pages**

Create minimal but real pages (the Inertia render tests need the files to exist; flesh out the markup in Task 6 if you prefer, but these are complete enough to ship). Each uses `defineOptions({ layout: AuthenticatedLayout })`, the Material tokens, and labeled inputs (date inputs need `aria-label`).

`resources/js/Pages/Finance/Reports/FinancialActivities.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ from: String, to: String, report: { type: Object, required: true } });
const from = ref(props.from); const to = ref(props.to);
const apply = () => router.get(route('finance.reports.financial-activities'), { from: from.value, to: to.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>
<template>
    <Head title="Statement of Financial Activities" />
    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div><h1 class="text-2xl font-black text-primary">Statement of Financial Activities</h1>
                <p class="text-on-surface-variant text-sm mt-1">{{ from }} → {{ to }}</p></div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>From <input type="date" v-model="from" aria-label="From date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <label>To <input type="date" v-model="to" aria-label="To date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
                <a :href="route('finance.reports.financial-activities.csv', { from, to })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">CSV</a>
            </div>
        </header>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-5">
            <section v-for="key in ['income','expenditure']" :key="key">
                <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-2">{{ key }}</h2>
                <table class="w-full text-sm">
                    <thead class="text-on-surface-variant text-[11px] uppercase"><tr><th class="text-left p-2">Account</th><th class="text-right p-2">Current</th><th class="text-right p-2">Prior</th></tr></thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="r in report[key].rows" :key="r.code"><td class="p-2 text-primary">{{ r.code }} {{ r.name }}</td><td class="p-2 text-right text-primary">{{ money(r.current) }}</td><td class="p-2 text-right text-on-surface-variant">{{ money(r.prior) }}</td></tr>
                    </tbody>
                    <tfoot class="font-black border-t border-outline-variant/50"><tr><td class="p-2">Total {{ key }}</td><td class="p-2 text-right">{{ money(report[key].total_current) }}</td><td class="p-2 text-right">{{ money(report[key].total_prior) }}</td></tr></tfoot>
                </table>
            </section>
            <div class="flex justify-between border-t border-outline-variant/60 pt-3 font-black text-primary">
                <span>Surplus / (Deficit)</span>
                <span>{{ money(report.surplus_current) }} <span class="text-on-surface-variant font-medium">(prior {{ money(report.surplus_prior) }})</span></span>
            </div>
        </div>
    </div>
</template>
```

`resources/js/Pages/Finance/Reports/FinancialPosition.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ asOf: String, report: { type: Object, required: true } });
const asOf = ref(props.asOf);
const apply = () => router.get(route('finance.reports.financial-position'), { as_of: asOf.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sections = [['assets','Assets'],['liabilities','Liabilities'],['equity','Equity / Funds']];
</script>
<template>
    <Head title="Statement of Financial Position" />
    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div><h1 class="text-2xl font-black text-primary">Statement of Financial Position</h1>
                <p class="text-on-surface-variant text-sm mt-1">As of {{ report.as_of }} (prior {{ report.comparative_as_of }})</p></div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>As of <input type="date" v-model="asOf" aria-label="As-of date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
            </div>
        </header>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-5">
            <section v-for="[key,label] in sections" :key="key">
                <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-2">{{ label }}</h2>
                <table class="w-full text-sm"><tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="r in report[key].rows" :key="r.code"><td class="p-2 text-primary">{{ r.code }} {{ r.name }}</td><td class="p-2 text-right text-primary">{{ money(r.current) }}</td><td class="p-2 text-right text-on-surface-variant">{{ money(r.prior) }}</td></tr>
                    <tr v-if="key === 'equity'"><td class="p-2 text-primary">Surplus / (Deficit) to date</td><td class="p-2 text-right text-primary">{{ money(report.surplus_current) }}</td><td class="p-2 text-right text-on-surface-variant">{{ money(report.surplus_prior) }}</td></tr>
                </tbody>
                <tfoot class="font-black border-t border-outline-variant/50"><tr><td class="p-2">Total {{ label }}</td>
                    <td class="p-2 text-right">{{ money(key === 'equity' ? report.total_funds_current : report[key].total_current) }}</td>
                    <td class="p-2 text-right">{{ money(key === 'equity' ? report.total_funds_prior : report[key].total_prior) }}</td></tr></tfoot></table>
            </section>
            <p :class="report.balanced_current ? 'text-emerald-300' : 'text-amber-300 font-bold'" class="text-sm">
                {{ report.balanced_current ? '✓ Balanced — Assets = Liabilities + Funds' : '⚠ Out of balance — investigate' }}
            </p>
        </div>
    </div>
</template>
```

`resources/js/Pages/Finance/Reports/AccountLedger.vue`:

```vue
<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ account: { type: Object, required: true }, from: String, to: String, lines: { type: Array, default: () => [] } });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>
<template>
    <Head :title="`Ledger — ${account.code}`" />
    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6"><h1 class="text-2xl font-black text-primary">{{ account.code }} · {{ account.name }}</h1>
            <p class="text-on-surface-variant text-sm mt-1">Posted lines{{ from ? ' from ' + from : '' }} to {{ to }}</p></header>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[11px] uppercase border-b border-outline-variant/40"><tr><th class="text-left p-3">Date</th><th class="text-left p-3">Reference</th><th class="text-left p-3">Narration</th><th class="text-right p-3">Debit</th><th class="text-right p-3">Credit</th></tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="(l, i) in lines" :key="i"><td class="p-3 text-on-surface-variant">{{ l.entry_date }}</td><td class="p-3 font-mono text-primary">{{ l.reference }}</td><td class="p-3 text-primary">{{ l.narration }}</td><td class="p-3 text-right text-primary">{{ l.debit ? money(l.debit) : '' }}</td><td class="p-3 text-right text-primary">{{ l.credit ? money(l.credit) : '' }}</td></tr>
                    <tr v-if="lines.length === 0"><td colspan="5" class="p-6 text-center text-on-surface-variant">No posted lines in this window.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Build + run the test**

Run: `npm run build` (expect success, no Vue compile errors)
Run: `php artisan test tests/Feature/Finance/CoreStatementsEndpointTest.php`
Expected: PASS (all five).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Finance/ReportController.php routes/web.php resources/js/Pages/Finance/Reports/FinancialActivities.vue resources/js/Pages/Finance/Reports/FinancialPosition.vue resources/js/Pages/Finance/Reports/AccountLedger.vue tests/Feature/Finance/CoreStatementsEndpointTest.php
git commit -m "feat(finance): Financial Activities + Position statements + GL drill-down (page + CSV)"
```

---

### Task 5: Link the statements from the Trial Balance page

**Files:**
- Modify: `resources/js/Pages/Finance/Reports/TrialBalance.vue`

- [ ] **Step 1: Add a simple report nav**

In `resources/js/Pages/Finance/Reports/TrialBalance.vue`, add a small nav row of `<Link>`s to the sibling statements just under the `<h1>` (import `Link` from `@inertiajs/vue3` — add it to the existing import line `import { Head, router } from '@inertiajs/vue3';` → `import { Head, Link, router } from '@inertiajs/vue3';`):

```vue
            <nav class="mt-2 flex gap-3 text-xs font-bold">
                <Link :href="route('finance.reports.trial-balance')" class="text-secondary">Trial Balance</Link>
                <Link :href="route('finance.reports.financial-activities')" class="text-on-surface-variant hover:text-secondary">Financial Activities</Link>
                <Link :href="route('finance.reports.financial-position')" class="text-on-surface-variant hover:text-secondary">Financial Position</Link>
            </nav>
```

(Place it right after the `<p ...>As of {{ asOf }}</p>` inside the header's first `<div>`.)

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Finance/Reports/TrialBalance.vue
git commit -m "feat(finance): cross-link financial statements from the Trial Balance page"
```

---

### Task 6: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Payroll tests/Feature/Loans tests/Feature/Disbursement`
Expected: PASS.

- [ ] **Step 2: Full app suite**

Run: `php artisan test`
Expected: PASS — accessibility green (all date inputs carry `aria-label`); the smoke test skips the new `.csv` route already. Allow only the known `KioskRecentTest` time-of-day flake if it is the sole failure.

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P3-2 core statements regression gate green"
```

---

## Self-Review notes (for the implementer)

- **The Financial Position equation holds by construction** for any balanced posted+reversed ledger: Assets = Liabilities + Equity + (cumulative Income − Expense). The `balanced_current` flag is the integrity proof — assert it true on real data.
- **Surplus on the Balance Sheet is cumulative** (lifetime income − expense to the as-of date), folded into funds — that's what makes the equation balance without period-end closing entries.
- **Comparatives**: SoFA prior = the immediately-preceding equal-length window (`priorPeriod`); SoFP prior = one year before the as-of date. Both reuse the engine.
- **Drill-down** lists `posted` + `reversed` lines (same status filter as the engine) so a reversed transaction's lines are visible (they net to zero in the balance but appear in the audit trail).
- **Accessibility**: every date input needs `aria-label` or `AccessibilityAuditorTest` fails.
- **Inertia render tests need the Vue files on disk** — create the three pages in Task 4 (as written) so the endpoint tests pass.

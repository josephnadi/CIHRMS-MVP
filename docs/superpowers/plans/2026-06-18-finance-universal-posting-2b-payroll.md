# Finance Universal Posting — Plan 2B: Payroll Accrual Posting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post a balanced, full-accrual journal entry to the GL whenever a payroll run is approved — debiting salary/allowance/employer-contribution expense and crediting net-pay, statutory payables, loan receivable + interest income (loan deductions), and a new voluntary-deductions payable — and reverse it when the run is reversed.

**Architecture:** Inject the `PostingService` (Plan 2A) into `PayrollService`. In `approve()`, after loan repayments are finalized, build a summarized `PostingDocument` from the run's cached totals (plus per-line expense splits and the run's finalized loan-repayment principal/interest) and post it with the approver as actor, keyed idempotently on `(Payroll, run_id, 'accrual')`. In `reverse()`, reverse that entry via `reverseFor()`.

**Tech Stack:** Laravel 13, PHP 8.3, Pest. Builds on Plan 2A's `PostingService::post(PostingDocument, ?User $actor)` and `reverseFor(...)`.

**This is Plan 2B of Plan 2.** 2A (actor threading) is merged. 2C (disbursement settlement) and 2D (loan disbursement) follow.

**Spec:** `docs/superpowers/specs/2026-06-16-finance-universal-posting-design.md`

## The accrual journal entry (confirmed design)

Summarized per run, posted at `approve()`. Zero-value lines are omitted. It balances by the net-pay identity `gross = net + PAYE + SSNIT-employee + voluntary` (employer SSNIT/Tier-2 are an expense+payable pair that nets to zero):

| DR/CR | Account (code) | Amount source |
|---|---|---|
| DR | Salaries Expense (5100) | Σ line `basic` + Σ line `overtime_pay` |
| DR | Allowances Expense (5110) | Σ line `allowance_total` |
| DR | Employer Statutory Contributions (5120) | `ssnit_tier1_employer_total` + `tier2_employer_total` |
| CR | Net Pay Payable (2300) | `net_total` |
| CR | PAYE Payable (2210) | `paye_total` |
| CR | SSNIT Payable (2200) | `ssnit_tier1_employee_total` + `ssnit_tier1_employer_total` (NHIA is routed by SSNIT, so it stays inside this remittance) |
| CR | Tier-2 Payable (2220) | `tier2_employer_total` |
| CR | Loan Receivable (1300) | Σ finalized repayments `principal_portion` |
| CR | Interest Income (4600) | Σ finalized repayments `interest_portion` |
| CR | Voluntary Deductions Payable (NEW 2250) | `voluntary_deductions_total` − (loan principal + interest) |

---

### Task 1: Voluntary Deductions Payable account + posting slug

The accrual splits loan deductions out of `voluntary_deductions_total` into Loan Receivable + Interest Income; the non-loan remainder needs a liability account the chart lacks.

**Files:**
- Modify: `database/seeders/ChartOfAccountsSeeder.php`
- Modify: `database/seeders/PostingAccountSeeder.php`
- Test: `tests/Feature/Finance/VoluntaryDeductionsAccountTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

it('seeds Voluntary Deductions Payable and maps the payroll slug to it', function () {
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();

    $gl = GlAccount::where('code', '2250')->first();
    expect($gl)->not->toBeNull()
        ->and($gl->name)->toBe('Voluntary Deductions Payable')
        ->and($gl->type->value)->toBe('liability');

    $rule = PostingAccount::where('slug', 'payroll.voluntary_deductions_payable')->firstOrFail();
    expect($rule->glAccount->code)->toBe('2250');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/VoluntaryDeductionsAccountTest.php`
Expected: FAIL — `2250` not found.

- [ ] **Step 3: Add the chart account**

In `database/seeders/ChartOfAccountsSeeder.php`, inside `const ACCOUNTS`, add this line in the Liabilities block right after the `'2240'` (NHIA Payable) line:

```php
        ['2250', 'Voluntary Deductions Payable', 'liability', '2000'],
```

- [ ] **Step 4: Add the posting slug**

In `database/seeders/PostingAccountSeeder.php`, inside `const RULES`, add this row (e.g. after the `payroll.net_pay_payable` row):

```php
        ['payroll.voluntary_deductions_payable', '2250', 'payroll', 'Non-statutory voluntary deductions owed', false],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/VoluntaryDeductionsAccountTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/ChartOfAccountsSeeder.php database/seeders/PostingAccountSeeder.php tests/Feature/Finance/VoluntaryDeductionsAccountTest.php
git commit -m "feat(finance): add Voluntary Deductions Payable account + payroll slug"
```

---

### Task 2: Post the accrual JE on payroll approval

Inject `PostingService` into `PayrollService` and post the accrual document at the end of `approve()`'s transaction (after loan repayments are finalized, so their `principal_portion`/`interest_portion` are queryable by `payroll_run_id`).

**Files:**
- Modify: `app/Services/Payroll/PayrollService.php`
- Test: `tests/Feature/Payroll/PayrollAccrualPostingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AmortizationMethod;
use App\Enums\IdentityVerificationStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\LoanProductType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\JournalEntry;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\LoanService;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();

    $dept  = Department::factory()->create();
    $grade = Grade::create(['code' => 'GS-12', 'name' => 'Senior Officer', 'level' => 12, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create(['grade_id' => $grade->id, 'step' => 1, 'base_salary' => 5_000, 'currency' => 'GHS', 'effective_from' => '2026-01-01']);

    $this->creator  = User::factory()->create(['role' => 'hr_admin']);
    $this->approver = User::factory()->create(['role' => 'finance_officer']);
    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id, 'current_grade_id' => $grade->id, 'current_step' => 1, 'status' => 'active',
    ]);
    IdentityVerification::create([
        'employee_id' => $this->employee->id, 'provider' => 'manual_upload',
        'ghana_card_number' => 'GHA-123456789-1',
        'ghana_card_hash' => IdentityVerification::hashCardNumber('GHA-123456789-1'),
        'status' => IdentityVerificationStatus::Verified->value,
        'verified_at' => now(), 'expires_at' => now()->addYear(),
    ]);
    \App\Models\AttendanceSummary::create([
        'employee_id' => $this->employee->id, 'summary_date' => CarbonImmutable::create(2026, 6, 1),
        'status' => 'present', 'hours_worked' => 8, 'overtime_hours' => 0,
    ]);
});

function balanceOf(string $code): float
{
    $gl = GlAccount::where('code', $code)->firstOrFail();
    return (float) GlAccountBalance::where('gl_account_id', $gl->id)->value('balance');
}

it('posts a balanced accrual JE on approval with no loans', function () {
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));
    $svc->approve($run, $this->approver);

    $je = JournalEntry::where('source_type', JournalSourceType::Payroll->value)
        ->where('source_id', $run->id)->where('source_purpose', 'accrual')->first();

    expect($je)->not->toBeNull()
        ->and($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->posted_by)->toBe($this->approver->id)
        ->and($je->isBalanced())->toBeTrue();

    // basic 5000, no allowance/overtime → Salaries Expense debit 5000.
    // SSNIT employee 275 + employer 650 = 925 in SSNIT Payable.
    // Employer contributions expense = 650 + Tier-2 (250) = 900.
    expect(balanceOf('5100'))->toBe(5000.0)   // Salaries Expense (asset/expense natural debit)
        ->and(balanceOf('2200'))->toBe(925.0) // SSNIT Payable
        ->and(balanceOf('2220'))->toBe(250.0) // Tier-2 Payable
        ->and(balanceOf('5120'))->toBe(900.0);// Employer Statutory Contributions

    // Net Pay Payable equals the run's net_total.
    expect(balanceOf('2300'))->toBe(round((float) $run->fresh()->net_total, 2));
});

it('credits Loan Receivable for loan deductions taken in payroll', function () {
    // 1200 loan, 0% interest, straight line over 6 → 200 principal/month, first due in the period.
    $product = LoanProduct::create([
        'code' => 'TST-001', 'name' => 'Test', 'type' => LoanProductType::Personal->value,
        'min_amount' => 100, 'max_amount' => 50_000, 'min_term_months' => 1, 'max_term_months' => 24,
        'annual_interest_rate' => 0, 'amortization_method' => AmortizationMethod::StraightLine->value,
        'is_active' => true, 'effective_from' => '2026-01-01', 'approvals_required' => 2,
    ]);
    $loans = app(LoanService::class);
    $loan = $loans->apply($this->employee, $product, 1_200, 6, null, $this->approver);
    $loan = $loans->approve($loan, $this->creator);
    $loans->disburse($loan, $this->approver, CarbonImmutable::create(2026, 6, 1));

    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));
    $svc->approve($run, $this->approver);

    $je = JournalEntry::where('source_type', JournalSourceType::Payroll->value)
        ->where('source_id', $run->id)->where('source_purpose', 'accrual')->firstOrFail();

    expect($je->isBalanced())->toBeTrue();

    // 200 principal recovered → Loan Receivable (asset) decreases by 200 (credit).
    // GlAccountBalance stores the natural balance; a credit to an asset is negative delta.
    expect(balanceOf('1300'))->toBe(-200.0);

    // The loan deduction is NOT left in voluntary deductions payable (2250 should be 0/absent line).
    expect(balanceOf('2250'))->toBe(0.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/PayrollAccrualPostingTest.php`
Expected: FAIL — no JE is posted yet (`$je` is null).

- [ ] **Step 3: Inject PostingService and add the accrual builder + post call**

In `app/Services/Payroll/PayrollService.php`:

(a) Add these imports near the other `use` statements:

```php
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\JournalEntry;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
```

(b) Add `PostingService` to the constructor (it currently injects paye, ssnit, tier2, allowances, deductions, attendance, loans):

```php
        private readonly LoanService $loans,
        private readonly PostingService $posting,
    ) {
    }
```

(c) In `approve()`, inside the `DB::transaction` closure, replace `event(new PayrollRunApproved($run));` with the post-then-event sequence:

```php
            $this->posting->post($this->buildAccrualDocument($run), $approver);

            event(new PayrollRunApproved($run));
```

(d) Add the private builder method to the class (anywhere among the private methods):

```php
    private function buildAccrualDocument(PayrollRun $run): PostingDocument
    {
        $basicPlusOvertime = round(
            (float) $run->lines()->calculated()->sum('basic')
            + (float) $run->lines()->calculated()->sum('overtime_pay'),
            2,
        );
        $allowance = round((float) $run->lines()->calculated()->sum('allowance_total'), 2);

        // Loan principal/interest were finalized just above (payroll_run_id is now set).
        $loanPrincipal = round((float) LoanRepayment::where('payroll_run_id', $run->id)->sum('principal_portion'), 2);
        $loanInterest  = round((float) LoanRepayment::where('payroll_run_id', $run->id)->sum('interest_portion'), 2);

        $employerContrib  = round((float) $run->ssnit_tier1_employer_total + (float) $run->tier2_employer_total, 2);
        $ssnitPayable     = round((float) $run->ssnit_tier1_employee_total + (float) $run->ssnit_tier1_employer_total, 2);
        $voluntaryNonLoan = round((float) $run->voluntary_deductions_total - $loanPrincipal - $loanInterest, 2);

        $debit  = fn (string $slug, float $amt, string $note) => $amt > 0 ? PostingLine::debit(slug: $slug, amount: $amt, narration: $note) : null;
        $credit = fn (string $slug, float $amt, string $note) => $amt > 0 ? PostingLine::credit(slug: $slug, amount: $amt, narration: $note) : null;

        $candidates = [
            $debit('payroll.salary_expense',              $basicPlusOvertime,                  'Basic + overtime'),
            $debit('payroll.allowance_expense',           $allowance,                          'Allowances'),
            $debit('payroll.employer_contrib_expense',    $employerContrib,                    'Employer SSNIT + Tier-2'),
            $credit('payroll.net_pay_payable',            round((float) $run->net_total, 2),   'Net pay'),
            $credit('payroll.paye_payable',               round((float) $run->paye_total, 2),  'PAYE'),
            $credit('payroll.ssnit_payable',              $ssnitPayable,                       'SSNIT employee + employer'),
            $credit('payroll.tier2_payable',              round((float) $run->tier2_employer_total, 2), 'Tier-2'),
            $credit('loan.principal_receivable',          $loanPrincipal,                      'Loan principal recovered'),
            $credit('loan.interest_income',               $loanInterest,                       'Loan interest recovered'),
            $credit('payroll.voluntary_deductions_payable', $voluntaryNonLoan,                 'Voluntary deductions'),
        ];

        $lines = array_values(array_filter($candidates));

        return new PostingDocument(
            sourceType: JournalSourceType::Payroll,
            sourceId: $run->id,
            purpose: 'accrual',
            date: $run->period_end->toDateString(),
            narration: "Payroll accrual: {$run->reference}",
            lines: $lines,
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Payroll/PayrollAccrualPostingTest.php`
Expected: PASS (both cases).

- [ ] **Step 5: Confirm existing payroll flow tests still pass**

Run: `php artisan test tests/Feature/Payroll`
Expected: PASS — the added posting happens after the existing approval logic; the existing flow tests (which seed only `GhanaStatutoryReferenceSeeder`) must still pass. NOTE: `approve()` now posts a JE, which requires the chart + balances + posting accounts to be seeded. If any existing payroll test calls `approve()` WITHOUT seeding the finance chart, it will fail because the posting accounts are unmapped. If that happens, STOP and report which tests — they will each need `ChartOfAccountsSeeder` + `GlAccountBalanceSeeder` + `PostingAccountSeeder` added to their `beforeEach`. Do NOT make `approve()` silently skip posting.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payroll/PayrollService.php tests/Feature/Payroll/PayrollAccrualPostingTest.php
git commit -m "feat(finance): post payroll accrual journal entry on approval"
```

---

### Task 3: Reverse the accrual JE on payroll reversal

When a run is reversed, reverse its accrual JE (if one was posted) so the GL unwinds.

**Files:**
- Modify: `app/Services/Payroll/PayrollService.php`
- Test: `tests/Feature/Payroll/PayrollReversalPostingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\IdentityVerificationStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();

    $dept  = Department::factory()->create();
    $grade = Grade::create(['code' => 'GS-12', 'name' => 'Senior Officer', 'level' => 12, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create(['grade_id' => $grade->id, 'step' => 1, 'base_salary' => 5_000, 'currency' => 'GHS', 'effective_from' => '2026-01-01']);

    $this->creator  = User::factory()->create(['role' => 'hr_admin']);
    $this->approver = User::factory()->create(['role' => 'finance_officer']);
    $this->reverser = User::factory()->create(['role' => 'finance_officer']);
    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id, 'current_grade_id' => $grade->id, 'current_step' => 1, 'status' => 'active',
    ]);
    IdentityVerification::create([
        'employee_id' => $this->employee->id, 'provider' => 'manual_upload',
        'ghana_card_number' => 'GHA-123456789-1',
        'ghana_card_hash' => IdentityVerification::hashCardNumber('GHA-123456789-1'),
        'status' => IdentityVerificationStatus::Verified->value,
        'verified_at' => now(), 'expires_at' => now()->addYear(),
    ]);
    \App\Models\AttendanceSummary::create([
        'employee_id' => $this->employee->id, 'summary_date' => CarbonImmutable::create(2026, 6, 1),
        'status' => 'present', 'hours_worked' => 8, 'overtime_hours' => 0,
    ]);
});

it('reverses the accrual JE and unwinds GL balances when a run is reversed', function () {
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));
    $svc->approve($run, $this->approver);

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(5000.0);

    $svc->reverse($run->fresh(), $this->reverser, 'wrong period');

    $accrual = JournalEntry::where('source_type', JournalSourceType::Payroll->value)
        ->where('source_id', $run->id)->where('source_purpose', 'accrual')->firstOrFail();

    expect($accrual->status)->toBe(JournalEntryStatus::Reversed)
        ->and((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(0.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Payroll/PayrollReversalPostingTest.php`
Expected: FAIL — the accrual stays `Posted` and the balance stays `5000` (reverse() doesn't touch the GL yet).

- [ ] **Step 3: Reverse the accrual in reverse()**

In `app/Services/Payroll/PayrollService.php`, inside `reverse()`'s `DB::transaction` closure, after `$run->lines()->update(['status' => 'reversed']);` and before `event(new PayrollRunReversed(...))`, add:

```php
            $this->reverseAccrualIfPosted($run, $reverser, $reason);
```

Add the private helper:

```php
    private function reverseAccrualIfPosted(PayrollRun $run, User $by, string $reason): void
    {
        $hasAccrual = JournalEntry::query()
            ->where('source_type', JournalSourceType::Payroll->value)
            ->where('source_id', $run->id)
            ->where('source_purpose', 'accrual')
            ->where('status', JournalEntryStatus::Posted->value)
            ->exists();

        if ($hasAccrual) {
            $this->posting->reverseFor(JournalSourceType::Payroll, $run->id, 'accrual', $by, "Payroll reversed: {$reason}");
        }
    }
```

(The `JournalEntry`, `JournalEntryStatus`, `JournalSourceType` imports were added in Task 2.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Payroll/PayrollReversalPostingTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payroll/PayrollService.php tests/Feature/Payroll/PayrollReversalPostingTest.php
git commit -m "feat(finance): reverse payroll accrual JE on run reversal"
```

---

### Task 4: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Full Payroll + Finance suites**

Run: `php artisan test tests/Feature/Payroll tests/Feature/Finance tests/Unit/Finance`
Expected: PASS. If any pre-existing payroll test that calls `approve()`/`reverse()` fails because it didn't seed the finance chart, add `ChartOfAccountsSeeder` + `GlAccountBalanceSeeder` + `PostingAccountSeeder` to that test's `beforeEach` (these are additive, deterministic seeders) and re-run. Do not weaken the posting.

- [ ] **Step 2: Commit any test-seeding fixes (if needed), then mark the gate**

```bash
git add -A
git commit -m "test(finance): seed finance chart in payroll tests that approve runs" --allow-empty
```

---

## Self-Review notes (for the implementer)

- **The JE balances by construction** via the net identity. If `PostingService::post()` throws "not balanced", the most likely cause is the loan split: `voluntaryNonLoan = voluntary_deductions_total − principal − interest` must use the SAME repayments that were finalized in `approve()` (keyed by `payroll_run_id`). Confirm `postRepayment` sets `payroll_run_id` before the builder runs (it does — the finalize loop runs first).
- **Asset credit sign:** crediting Loan Receivable (an asset) produces a NEGATIVE delta in `gl_account_balances` (natural balance convention). The test asserts `balanceOf('1300') === -200.0` deliberately.
- **Zero-line omission:** `array_filter` drops null `PostingLine`s so a run with no allowances/loans/voluntary still produces a valid ≥2-line balanced document.
- **Pre-existing payroll tests:** any that approve a run now need the finance chart seeded. This is expected fallout of making approval post to the GL — fix by adding the three seeders to their `beforeEach`, not by making posting conditional.
- **Out of scope:** reversing a payroll run does NOT restore loan subledger balances (`LoanAccount.outstanding_balance`) — that is pre-existing behavior of `reverse()` and a separate concern. Only the accrual JE is reversed here.

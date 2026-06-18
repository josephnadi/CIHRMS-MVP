# Finance Universal Posting — Plan 2D: Loan Disbursement Posting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post a journal entry when a staff loan is disbursed — Dr Loan Receivable, Cr the organisation's operating bank — so the GL recognises the receivable and the cash outflow. (Loan repayment is already posted by the payroll accrual in Plan 2B.)

**Architecture:** Inject `PostingService` into `LoanService`. In `disburse()`, after the loan is transitioned to `Disbursed`, post a balanced `PostingDocument` keyed on `(LoanDisbursement, loan_id, 'disbursement')`, debiting Loan Receivable (slug `loan.principal_receivable`) and crediting the GL of the active `OrgBankAccount` with purpose `Operating`, both for the loan principal, attributed to the disburser.

**Tech Stack:** Laravel 13, PHP 8.3, Pest. Builds on Plan 2A (`PostingService`) and 2B (which credits Loan Receivable on repayment). Completes the loan GL lifecycle: disbursement debits the receivable here; payroll repayments credit it back down (2B).

**This is Plan 2D of Plan 2 — the final wiring sub-plan.** 2A+2B+2C are merged to `main`. After 2D, every money event (payroll, disbursement, loans) posts to the GL; member fees & Paystack already do (Plan 3 refactor candidates).

**Spec:** `docs/superpowers/specs/2026-06-16-finance-universal-posting-design.md`

## The disbursement journal entry (confirmed design)

Posted per loan at `disburse()`:

| DR/CR | Account | Amount |
|---|---|---|
| DR | Loan Receivable (1300, slug `loan.principal_receivable`) | `loan.principal` |
| CR | Operating bank (GL of active `OrgBankAccount` purpose=Operating, by literal id) | `loan.principal` |

Interest is NOT recognised at disbursement — it is earned over the repayment schedule and credited to Interest Income as each installment is recovered through payroll (Plan 2B). If no active operating bank exists, disbursement throws a clear `DomainException`.

**Ripple:** three existing tests disburse a loan and now need the operating bank (and finance chart) seeded; Plan 2B's `PayrollAccrualPostingTest` loan case asserted `balanceOf('1300') === -200.0` (repayment only). After 2D the disbursement debits +1200 first, so the net becomes **+1000.0** (1200 disbursed − 200 repaid) — which now correctly mirrors the loan's outstanding balance. That assertion is updated here.

---

### Task 1: Post the disbursement JE in LoanService::disburse

**Files:**
- Modify: `app/Services/Loans/LoanService.php`
- Modify: `tests/Feature/Loans/LoanServiceTest.php` (seed finance chart + banks — it disburses)
- Modify: `tests/Feature/Payroll/PayrollLoanIntegrationTest.php` (add operating-bank seeder — it disburses in beforeEach)
- Modify: `tests/Feature/Payroll/PayrollAccrualPostingTest.php` (add operating-bank seeder + update the loan-receivable assertion)
- Test: `tests/Feature/Loans/LoanDisbursementPostingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AmortizationMethod;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\LoanProductType;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\LoanService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run(); // active Operating-purpose account → GL 1100

    $this->product = LoanProduct::create([
        'code' => 'TST-001', 'name' => 'Test Personal', 'type' => LoanProductType::Personal->value,
        'min_amount' => 1_000, 'max_amount' => 50_000, 'min_term_months' => 3, 'max_term_months' => 36,
        'annual_interest_rate' => 0.12, 'amortization_method' => AmortizationMethod::ReducingBalance->value,
        'is_active' => true, 'effective_from' => '2026-01-01', 'approvals_required' => 2,
    ]);
    $this->applicant = User::factory()->create(['role' => 'employee']);
    $this->approver  = User::factory()->create(['role' => 'finance_officer']);
    $this->employee  = Employee::factory()->create(['user_id' => $this->applicant->id]);
    $this->svc = app(LoanService::class);
});

it('posts a balanced disbursement JE: Dr Loan Receivable / Cr operating bank', function () {
    $loan = $this->svc->apply($this->employee, $this->product, 6_000, 6, null, $this->applicant);
    $loan = $this->svc->approve($loan, $this->approver);
    $loan = $this->svc->disburse($loan, $this->approver, CarbonImmutable::create(2026, 7, 1));

    $je = JournalEntry::where('source_type', JournalSourceType::LoanDisbursement->value)
        ->where('source_id', $loan->id)->where('source_purpose', 'disbursement')->firstOrFail();

    expect($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->posted_by)->toBe($this->approver->id)
        ->and($je->isBalanced())->toBeTrue();

    $recv = GlAccount::where('code', '1300')->firstOrFail(); // Loan Receivable (asset)
    $bank = GlAccount::where('code', '1100')->firstOrFail(); // Operating bank (asset)
    $drLine = $je->lines->firstWhere('gl_account_id', $recv->id);
    $crLine = $je->lines->firstWhere('gl_account_id', $bank->id);

    expect((float) $drLine->debit_amount)->toBe(6000.0)
        ->and((float) $crLine->credit_amount)->toBe(6000.0);

    // Loan Receivable (asset, natural debit) increases by the principal; bank decreases.
    expect((float) GlAccountBalance::where('gl_account_id', $recv->id)->value('balance'))->toBe(6000.0)
        ->and((float) GlAccountBalance::where('gl_account_id', $bank->id)->value('balance'))->toBe(-6000.0);
});

it('throws when no active operating bank account is configured', function () {
    \App\Models\OrgBankAccount::query()->update(['is_active' => false]);

    $loan = $this->svc->apply($this->employee, $this->product, 6_000, 6, null, $this->applicant);
    $loan = $this->svc->approve($loan, $this->approver);

    expect(fn () => $this->svc->disburse($loan, $this->approver, CarbonImmutable::create(2026, 7, 1)))
        ->toThrow(DomainException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Loans/LoanDisbursementPostingTest.php`
Expected: FAIL — no JE is posted (`firstOrFail` finds nothing) / `disburse()` doesn't post.

- [ ] **Step 3: Inject PostingService + post the disbursement JE**

In `app/Services/Loans/LoanService.php`:

(a) Add imports (User is already imported; SequenceService is `App\Services\Finance\SequenceService`):

```php
use App\Enums\JournalSourceType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\OrgBankAccount;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
```

(b) Add `PostingService` to the constructor (currently `AmortizationCalculator $calc, SequenceService $sequences`):

```php
    public function __construct(
        private readonly AmortizationCalculator $calc,
        private readonly SequenceService $sequences,
        private readonly PostingService $posting,
    ) {}
```

(c) In `disburse()`, inside the `DB::transaction` closure, after `$loan->update([...])` and BEFORE `event(new LoanDisbursed($loan));`, add:

```php
            $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::LoanDisbursement,
                sourceId: $loan->id,
                purpose: 'disbursement',
                date: now()->toDateString(),
                narration: "Loan disbursement: {$loan->reference}",
                lines: [
                    PostingLine::debit(slug: 'loan.principal_receivable', amount: (float) $loan->principal, narration: 'Loan principal advanced'),
                    PostingLine::credit(accountId: $this->resolveOperatingBankGlId(), amount: (float) $loan->principal, narration: 'Cash disbursed'),
                ],
            ), $disburser);
```

(d) Add the private helper:

```php
    private function resolveOperatingBankGlId(): int
    {
        $bank = OrgBankAccount::query()
            ->where('purpose', OrgBankAccountPurpose::Operating->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $bank || ! $bank->gl_account_id) {
            throw new \DomainException('No active operating bank account is configured; cannot post loan disbursement.');
        }

        return (int) $bank->gl_account_id;
    }
```

- [ ] **Step 4: Run the new test to verify it passes**

Run: `php artisan test tests/Feature/Loans/LoanDisbursementPostingTest.php`
Expected: PASS (both).

- [ ] **Step 5: Seed finance chart + banks in LoanServiceTest**

`tests/Feature/Loans/LoanServiceTest.php` disburses loans but seeds no finance data. Add to the TOP of its `beforeEach` (before the LoanProduct creation):

```php
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\OrgBankAccountSeeder())->run();
```

- [ ] **Step 6: Seed the operating bank in PayrollLoanIntegrationTest**

`tests/Feature/Payroll/PayrollLoanIntegrationTest.php` disburses a loan in its `beforeEach` (and already got the chart/balances/posting seeders in Plan 2B). Add the operating-bank seeder right after the existing `PostingAccountSeeder` line in its `beforeEach`:

```php
    (new \Database\Seeders\OrgBankAccountSeeder())->run();
```

- [ ] **Step 7: Update PayrollAccrualPostingTest's loan case**

`tests/Feature/Payroll/PayrollAccrualPostingTest.php` — the `credits Loan Receivable for loan deductions taken in payroll` test now also triggers a disbursement posting. Two edits:

(a) Add the operating-bank seeder to its `beforeEach`, right after the existing `PostingAccountSeeder` line:

```php
    (new \Database\Seeders\OrgBankAccountSeeder())->run();
```

(b) In that loan test, the disbursement now debits Loan Receivable +1200 before payroll credits it −200, so change the assertion:

```php
        ->and(balanceOf('1300'))->toBe(-200.0)
```

to:

```php
        ->and(balanceOf('1300'))->toBe(1000.0) // +1200 disbursed − 200 repaid = outstanding
```

- [ ] **Step 8: Run the affected suites**

Run: `php artisan test tests/Feature/Loans tests/Feature/Payroll`
Expected: PASS — all loan + payroll tests green with the new seeders and updated assertion.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Loans/LoanService.php tests/Feature/Loans/ tests/Feature/Payroll/PayrollLoanIntegrationTest.php tests/Feature/Payroll/PayrollAccrualPostingTest.php
git commit -m "feat(finance): post loan disbursement JE (Dr Loan Receivable / Cr operating bank)"
```

---

### Task 2: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Loans + Payroll + Finance + Disbursement suites**

Run: `php artisan test tests/Feature/Loans tests/Feature/Payroll tests/Feature/Finance tests/Unit/Finance tests/Feature/Disbursement`
Expected: PASS. If any other test disburses a loan without seeding the operating bank + finance chart, add the four seeders (`ChartOfAccountsSeeder`, `GlAccountBalanceSeeder`, `PostingAccountSeeder`, `OrgBankAccountSeeder`) to its `beforeEach`.

- [ ] **Step 2: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): Plan 2D loan-disbursement regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Loan GL lifecycle is now complete:** disbursement debits Loan Receivable (this plan); each payroll repayment credits principal back to Loan Receivable and the interest portion to Interest Income (Plan 2B). After full repayment, Loan Receivable for that loan nets to zero.
- **The +1000 assertion** in `PayrollAccrualPostingTest` is the correct post-2D value and proves the two halves agree: 1200 advanced − 200 first installment = 1000 outstanding.
- **Actor:** `disburse()` passes `$disburser` explicitly, so the JE is attributed to the approving officer (no system-user fallback needed — auth is present in the controller path anyway).
- **Operating bank, fail-loud** if absent — mirrors the payroll-bank resolution in Plan 2C.
- **No DI binding change needed:** `LoanService` is resolved straight from the container (unlike `BatchDisbursementService`), so adding a constructor dependency is auto-wired.
- **Interest at disbursement = none:** only principal moves cash; interest is income recognised over the schedule.

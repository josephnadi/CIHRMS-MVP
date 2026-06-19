# Finance — Settlement→GL S-1: Accrual + Loan Clearing + Subledger Fix

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post a balanced accrual journal entry when a final settlement is approved — recognising the termination expense and clearing the off-boarding loan correctly against the ledger (principal→1300, interest→4600) — and fix the subledger so the cleared principal drops in lockstep instead of being silently masked.

**Architecture:** A new `SettlementPostingService::postAccrual` computes how much loan the gross settlement can absorb (clearing whole installments oldest-first up to capacity), waives exactly those installments, and posts one balanced `PostingDocument` through the existing `PostingService` choke point. `OffboardingService::approveSettlement` calls it in place of the old `closeOutstandingLoans`. `SubledgerReconciliationService` stops counting `Waived` principal.

**Tech Stack:** Laravel 13, Pest, PHP 8.3.

## Global Constraints

- All GL writes go through `PostingService::post(PostingDocument, ?User $actor)` — never hand-build `JournalEntry`/`JournalLine`.
- `PostingLine` forbids zero-amount legs and `PostingDocument` requires ≥2 lines — **omit any zero-amount credit leg**.
- Loan clearing credits `1300` by **principal** and `4600` by **interest** of the cleared installments — never by `outstanding_balance`.
- Idempotency: `source_purpose = 'accrual'` on `(FinalSettlement, settlement.id)`; never double-post.
- `declare(strict_types=1)` on new classes; tolerance for money comparisons is `0.005`.

**This is S-1 of the settlement→GL spec.** S-2 (payment leg + UI) follows.

**Spec:** `docs/superpowers/specs/2026-06-19-finance-settlement-gl-posting-design.md`

---

### Task 1: Foundations — source type, GL account 5130, posting slugs

**Files:**
- Modify: `app/Enums/JournalSourceType.php`
- Modify: `database/seeders/ChartOfAccountsSeeder.php`
- Modify: `database/seeders/PostingAccountSeeder.php`
- Test: `tests/Feature/Finance/SettlementPostingAccountsTest.php`

**Interfaces:**
- Produces: `JournalSourceType::FinalSettlement` (`'final_settlement'`); GL account `5130` (expense); posting slugs `settlement.benefits_expense`→5130, `settlement.paye_payable`→2210, `settlement.deductions_payable`→2250, `settlement.net_pay_payable`→2300.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Services\Finance\AccountResolver;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
});

it('exposes the FinalSettlement source type', function () {
    expect(JournalSourceType::FinalSettlement->value)->toBe('final_settlement')
        ->and(JournalSourceType::FinalSettlement->label())->toBe('Final Settlement');
});

it('seeds the 5130 termination benefits expense account', function () {
    $acc = GlAccount::where('code', '5130')->first();
    expect($acc)->not->toBeNull()
        ->and($acc->type->value)->toBe('expense');
});

it('maps the settlement posting slugs to the right accounts', function () {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve('settlement.benefits_expense')->code)->toBe('5130')
        ->and($resolver->resolve('settlement.paye_payable')->code)->toBe('2210')
        ->and($resolver->resolve('settlement.deductions_payable')->code)->toBe('2250')
        ->and($resolver->resolve('settlement.net_pay_payable')->code)->toBe('2300');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/SettlementPostingAccountsTest.php`
Expected: FAIL — enum case, account, and slugs missing.

- [ ] **Step 3: Add the source type**

In `app/Enums/JournalSourceType.php`, add the case (after `MemberFee`):

```php
    case FinalSettlement  = 'final_settlement';
```

and in `label()` (after the `MemberFee` arm):

```php
            self::FinalSettlement   => 'Final Settlement',
```

- [ ] **Step 4: Add the GL account**

In `database/seeders/ChartOfAccountsSeeder.php`, add to the Expense block (after the `5120` row, keeping parent order — parent `5000` already precedes it):

```php
        ['5130', 'Termination & Severance Benefits', 'expense', '5000'],
```

- [ ] **Step 5: Add the posting slugs**

In `database/seeders/PostingAccountSeeder.php`, add to `const RULES` (after the existing rows):

```php
        ['settlement.benefits_expense',     '5130', 'offboarding', 'Final settlement gross (gratuity/severance/leave/etc.)', false],
        ['settlement.paye_payable',         '2210', 'offboarding', 'PAYE withheld on a final settlement',                    true],
        ['settlement.deductions_payable',   '2250', 'offboarding', 'Garnishments & other settlement deductions',            false],
        ['settlement.net_pay_payable',      '2300', 'offboarding', 'Net final settlement owed to the leaver',               true],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/SettlementPostingAccountsTest.php`
Expected: PASS (all three).

- [ ] **Step 7: Commit**

```bash
git add app/Enums/JournalSourceType.php database/seeders/ChartOfAccountsSeeder.php database/seeders/PostingAccountSeeder.php tests/Feature/Finance/SettlementPostingAccountsTest.php
git commit -m "feat(finance): settlement source type, 5130 benefits account, posting slugs"
```

---

### Task 2: SettlementPostingService::postAccrual (compute + waive + post)

**Files:**
- Create: `app/Services/Offboarding/SettlementPostingService.php`
- Test: `tests/Feature/Offboarding/SettlementAccrualTest.php`

**Interfaces:**
- Consumes: `PostingService::post(PostingDocument, ?User)`; `FinalSettlement`, `OffboardingCase`, `LoanAccount`, `LoanRepayment`, `LoanStatus`, `LoanRepaymentStatus`, `JournalSourceType`; the Task 1 slugs.
- Produces: `SettlementPostingService::postAccrual(FinalSettlement $settlement, User $actor): ?JournalEntry` — posts the balanced accrual JE (purpose `'accrual'`), waives the cleared installments, returns the JE (or `null` when `gross_settlement <= 0`, in which case nothing is recognised and no loan is touched).

**Behaviour (the contract this task implements):**
- `capacity = max(0, gross − paye − garnishments − other_deductions)`.
- Walk the employee's open loans' `Scheduled` installments oldest-first (`due_period`, `installment_no`), accepting an installment while the running `scheduled_amount` total stays `≤ capacity + 0.005`; stop at the first that doesn't fit.
- `principalCleared = Σ principal_portion`, `interestCleared = Σ interest_portion`, `loanCleared = principalCleared + interestCleared` of the accepted installments. `netPay = gross − paye − deductions − loanCleared` (≥ 0).
- Waive the accepted installments (`Waived`, note, `posted_at`); per affected loan, flip to `PaidOff` (balance 0, `actual_end_date`) when no `Scheduled` installments remain, else reduce `outstanding_balance` by the cleared amount and leave it `Repaying`.
- Build the accrual doc: DR `settlement.benefits_expense` = gross; CR each of PAYE / principal / interest / deductions / netPay **only when > 0**.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\LoanAccount;
use App\Models\LoanRepayment;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // actor-less posting fallback
});

/** Natural balance of a GL account by code. */
function settlementGl(string $code): float
{
    return (float) GlAccountBalance::query()
        ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
        ->where('gl_accounts.code', $code)
        ->value('gl_account_balances.balance');
}

/**
 * Build an off-boarding case + employee + an open loan with N scheduled
 * installments (principal + interest per installment), plus a calculated
 * settlement row. Returns [$settlement, $loan].
 */
function seedSettlementWithLoan(array $opts = []): array
{
    $employee = Employee::factory()->create();
    $case = OffboardingCase::create([
        'reference' => 'OFF-T-' . uniqid(), 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'exit_type' => 'resignation',
        'status' => 'awaiting_settlement', 'notice_received_on' => '2026-06-01',
        'last_working_day' => '2026-06-30', 'effective_termination_date' => '2026-06-30',
    ]);

    $loan = LoanAccount::create([
        'reference' => 'LN-T-' . uniqid(), 'employee_id' => $employee->id,
        'status' => LoanStatus::Repaying->value, 'principal' => $opts['principal'] ?? 3000,
        'term_months' => 3, 'monthly_installment' => 1100, 'total_interest' => $opts['interest'] ?? 300,
        'total_repayable' => ($opts['principal'] ?? 3000) + ($opts['interest'] ?? 300),
        'disbursed_amount' => $opts['principal'] ?? 3000,
        'outstanding_balance' => $opts['outstanding'] ?? 3300,
    ]);
    // 3 installments: principal 1000 + interest 100 each (defaults)
    for ($i = 1; $i <= 3; $i++) {
        LoanRepayment::create([
            'loan_account_id' => $loan->id, 'installment_no' => $i,
            'due_period' => sprintf('2026-%02d-01', 6 + $i),
            'scheduled_amount' => 1100, 'principal_portion' => 1000, 'interest_portion' => 100,
            'balance_after' => 1100 * (3 - $i), 'status' => LoanRepaymentStatus::Scheduled->value,
        ]);
    }

    $settlement = FinalSettlement::create([
        'offboarding_case_id' => $case->id, 'status' => 'approved',
        'basic_salary' => 2000, 'years_of_service' => 3, 'accrued_leave_days' => 0,
        'working_days_per_month' => 22,
        'gratuity' => $opts['gross'] ?? 10000, 'severance' => 0, 'leave_encashment' => 0,
        'prorated_13th_month' => 0, 'ex_gratia' => 0, 'gross_settlement' => $opts['gross'] ?? 10000,
        'outstanding_loans' => $opts['outstanding'] ?? 3300, 'garnishments' => $opts['garn'] ?? 0,
        'other_deductions' => $opts['other'] ?? 0,
        'total_deductions' => ($opts['outstanding'] ?? 3300) + ($opts['garn'] ?? 0) + ($opts['other'] ?? 0) + ($opts['paye'] ?? 0),
        'paye_on_settlement' => $opts['paye'] ?? 0,
        'net_payable' => ($opts['gross'] ?? 10000) - (($opts['outstanding'] ?? 3300) + ($opts['garn'] ?? 0) + ($opts['other'] ?? 0) + ($opts['paye'] ?? 0)),
        'calculated_by' => User::factory()->create()->id, 'calculated_at' => now(),
        'breakdown' => [],
    ]);

    return [$settlement, $loan];
}

it('posts a balanced accrual that clears the loan (principal→1300, interest→4600)', function () {
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);

    $je = app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect($je)->not->toBeNull()
        ->and($je->source_type)->toBe(JournalSourceType::FinalSettlement->value)
        ->and($je->source_purpose)->toBe('accrual');

    // Loan fully cleared: 1300 nets to 0 for the 3000 principal; 4600 = 300 interest.
    expect(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01)   // principal removed
        ->and(settlementGl('4600'))->toEqualWithDelta(300.0, 0.01) // interest income
        ->and(settlementGl('2210'))->toEqualWithDelta(500.0, 0.01) // PAYE payable
        ->and(settlementGl('5130'))->toEqualWithDelta(10000.0, 0.01) // expense
        ->and(settlementGl('2300'))->toEqualWithDelta(6200.0, 0.01); // net = 10000-500-3300

    // Installments waived; loan paid off.
    expect(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(0)
        ->and($loan->fresh()->status)->toBe(LoanStatus::PaidOff);
});

it('is idempotent — re-posting returns the same JE and does not double-clear', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    $svc = app(SettlementPostingService::class);

    $first = $svc->postAccrual($settlement, User::factory()->create());
    $countAfterFirst = JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)->count();
    $svc->postAccrual($settlement->fresh(), User::factory()->create());

    expect(JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)->count())->toBe($countAfterFirst)
        ->and(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01); // not double-credited
});

it('shortfall: clears the loan only up to gross, leaving the rest scheduled and owed', function () {
    // gross 1500 < loan 3300 → only one 1100 installment fits (2200 would exceed 1500).
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 1500, 'outstanding' => 3300, 'paye' => 0]);

    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    // Only 1 installment cleared: principal 1000 → 1300 drops from 3000 to 2000; interest 100 → 4600.
    expect(settlementGl('1300'))->toEqualWithDelta(2000.0, 0.01)
        ->and(settlementGl('4600'))->toEqualWithDelta(100.0, 0.01)
        ->and(settlementGl('5130'))->toEqualWithDelta(1500.0, 0.01)
        ->and(settlementGl('2300'))->toEqualWithDelta(400.0, 0.01); // net = 1500 - 1100

    expect(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(2)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Repaying); // still owed
});

it('returns null and touches nothing when gross is zero', function () {
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 0]);

    $je = app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect($je)->toBeNull()
        ->and(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(3);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Offboarding/SettlementAccrualTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

`app/Services/Offboarding/SettlementPostingService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Offboarding;

use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Models\FinalSettlement;
use App\Models\JournalEntry;
use App\Models\LoanAccount;
use App\Models\LoanRepayment;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Illuminate\Support\Collection;

/**
 * Posts the General-Ledger accrual for an approved final settlement and clears
 * the leaver's loans against it. The gross settlement is the expense (5130);
 * PAYE, the cleared loan (principal→1300, interest→4600), other deductions, and
 * the net payable are the credits. The loan is cleared only as far as the gross
 * can absorb it (whole installments, oldest first) — any uncovered installments
 * stay scheduled and owed.
 */
class SettlementPostingService
{
    private const TOLERANCE = 0.005;

    public function __construct(private readonly PostingService $posting)
    {
    }

    public function postAccrual(FinalSettlement $settlement, User $actor): ?JournalEntry
    {
        $gross = round((float) $settlement->gross_settlement, 2);
        if ($gross <= 0.0) {
            return null; // nothing to recognise; no loan can be netted
        }

        $paye       = round((float) $settlement->paye_on_settlement, 2);
        $deductions = round((float) $settlement->garnishments + (float) $settlement->other_deductions, 2);
        $capacity   = round(max(0.0, $gross - $paye - $deductions), 2);

        $cleared = $this->selectClearableInstallments($settlement, $capacity);

        $principalCleared = round((float) $cleared->sum(fn (LoanRepayment $i) => (float) $i->principal_portion), 2);
        $interestCleared  = round((float) $cleared->sum(fn (LoanRepayment $i) => (float) $i->interest_portion), 2);
        $loanCleared      = round($principalCleared + $interestCleared, 2);
        $netPay           = round($gross - $paye - $deductions - $loanCleared, 2);

        $this->applyClearing($cleared, $settlement);

        $lines = [PostingLine::debit(slug: 'settlement.benefits_expense', amount: $gross, narration: 'Final settlement gross')];
        if ($paye > 0.0) {
            $lines[] = PostingLine::credit(slug: 'settlement.paye_payable', amount: $paye, narration: 'PAYE on settlement');
        }
        if ($principalCleared > 0.0) {
            $lines[] = PostingLine::credit(slug: 'loan.principal_receivable', amount: $principalCleared, narration: 'Loan principal cleared from settlement');
        }
        if ($interestCleared > 0.0) {
            $lines[] = PostingLine::credit(slug: 'loan.interest_income', amount: $interestCleared, narration: 'Loan interest collected via settlement');
        }
        if ($deductions > 0.0) {
            $lines[] = PostingLine::credit(slug: 'settlement.deductions_payable', amount: $deductions, narration: 'Garnishments & other deductions');
        }
        if ($netPay > 0.0) {
            $lines[] = PostingLine::credit(slug: 'settlement.net_pay_payable', amount: $netPay, narration: 'Net settlement payable');
        }

        return $this->posting->post(new PostingDocument(
            sourceType: JournalSourceType::FinalSettlement,
            sourceId: $settlement->id,
            purpose: 'accrual',
            date: now()->toDateString(),
            narration: "Final settlement accrual (case {$settlement->offboarding_case_id})",
            lines: $lines,
        ), $actor);
    }

    /** Whole scheduled installments, oldest first, whose running total fits the capacity. */
    private function selectClearableInstallments(FinalSettlement $settlement, float $capacity): Collection
    {
        $case = OffboardingCase::find($settlement->offboarding_case_id);
        $employeeId = $case?->employee_id;
        if ($employeeId === null) {
            return collect();
        }

        $loanIds = LoanAccount::where('employee_id', $employeeId)
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value])
            ->lockForUpdate()
            ->pluck('id');

        if ($loanIds->isEmpty()) {
            return collect();
        }

        $installments = LoanRepayment::whereIn('loan_account_id', $loanIds)
            ->where('status', LoanRepaymentStatus::Scheduled->value)
            ->orderBy('due_period')
            ->orderBy('installment_no')
            ->lockForUpdate()
            ->get();

        $cleared = collect();
        $running = 0.0;
        foreach ($installments as $inst) {
            $next = round($running + (float) $inst->scheduled_amount, 2);
            if ($next <= $capacity + self::TOLERANCE) {
                $cleared->push($inst);
                $running = $next;
            } else {
                break;
            }
        }

        return $cleared;
    }

    /** Waive the cleared installments and update each affected loan. */
    private function applyClearing(Collection $cleared, FinalSettlement $settlement): void
    {
        if ($cleared->isEmpty()) {
            return;
        }

        foreach ($cleared->groupBy('loan_account_id') as $loanId => $insts) {
            LoanRepayment::whereIn('id', $insts->pluck('id'))->update([
                'status'    => LoanRepaymentStatus::Waived->value,
                'notes'     => 'Cleared from final settlement ' . $settlement->id,
                'posted_at' => now(),
            ]);

            $loan = LoanAccount::find($loanId);
            if (! $loan) {
                continue;
            }

            $remaining = LoanRepayment::where('loan_account_id', $loanId)
                ->where('status', LoanRepaymentStatus::Scheduled->value)
                ->count();

            if ($remaining === 0) {
                $loan->update([
                    'status'              => LoanStatus::PaidOff->value,
                    'outstanding_balance' => 0,
                    'actual_end_date'     => now()->toDateString(),
                ]);
            } else {
                $clearedAmt = round((float) $insts->sum(fn (LoanRepayment $i) => (float) $i->scheduled_amount), 2);
                $loan->update([
                    'outstanding_balance' => round(max(0.0, (float) $loan->outstanding_balance - $clearedAmt), 2),
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Offboarding/SettlementAccrualTest.php`
Expected: PASS (all four).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Offboarding/SettlementPostingService.php tests/Feature/Offboarding/SettlementAccrualTest.php
git commit -m "feat(finance): SettlementPostingService accrual (loan clearing principal/interest split + shortfall floor)"
```

---

### Task 3: Subledger reconciliation — exclude Waived principal

**Files:**
- Modify: `app/Services/Finance/SubledgerReconciliationService.php`
- Test: `tests/Feature/Finance/SubledgerWaivedLoanTest.php`

**Interfaces:**
- Modifies: `loanPrincipalOutstanding()` to exclude both `Paid` and `Waived` installments (so a settlement-cleared installment leaves the subledger total exactly as the GL credit leaves 1300).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Finance\SubledgerReconciliationService;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/../Offboarding/SettlementAccrualTest.php'; // reuse seedSettlementWithLoan + settlementGl helpers

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

it('shows no 1300 variance after a settlement clears a loan (subledger drops with the GL)', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);

    // Before clearing: disburse-equivalent state isn't posted here, so seed GL via the accrual itself.
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    $rows = collect(app(SubledgerReconciliationService::class)->reconcile());
    $loanRow = $rows->firstWhere('gl_code', '1300');

    // Subledger principal-outstanding (excludes Waived) and GL 1300 both reflect the cleared loan.
    expect($loanRow['in_balance'])->toBeTrue();
});
```

> Note: this test asserts the **post-fix invariant** (subledger and GL agree after a clear). With the pre-fix code, `loanPrincipalOutstanding` would still count the `Waived` principal while GL 1300 dropped, producing a variance — the regression this task removes.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/SubledgerWaivedLoanTest.php`
Expected: FAIL — Waived principal still counted, variance present.

- [ ] **Step 3: Apply the fix**

In `app/Services/Finance/SubledgerReconciliationService.php`, change `loanPrincipalOutstanding()`:

```php
    private function loanPrincipalOutstanding(): float
    {
        return (float) LoanRepayment::query()
            ->whereNotIn('status', [LoanRepaymentStatus::Paid->value, LoanRepaymentStatus::Waived->value])
            ->sum('principal_portion');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/SubledgerWaivedLoanTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/SubledgerReconciliationService.php tests/Feature/Finance/SubledgerWaivedLoanTest.php
git commit -m "fix(finance): subledger excludes waived loan principal (reconciles with settlement clearing)"
```

---

### Task 4: Wire into approveSettlement + end-to-end + gate

**Files:**
- Modify: `app/Services/Offboarding/OffboardingService.php`
- Test: `tests/Feature/Offboarding/ApproveSettlementPostsAccrualTest.php`

**Interfaces:**
- `OffboardingService` now depends on `SettlementPostingService`; `approveSettlement` calls `postAccrual($settlement, $approver)` (inside its existing transaction) in place of `closeOutstandingLoans`. The old `closeOutstandingLoans` private method is removed (its waiving logic now lives in the posting service).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php'; // reuse seedSettlementWithLoan + settlementGl

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

it('posts the accrual JE when a settlement is approved', function () {
    // seedSettlementWithLoan creates an already-"approved" row; recreate it as Calculated so approveSettlement runs.
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    $settlement->update(['status' => 'calculated']);

    $approver = User::factory()->create(['role' => 'super_admin']);
    app(OffboardingService::class)->approveSettlement($settlement->fresh(), $approver);

    $je = JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
        ->where('source_id', $settlement->id)->where('source_purpose', 'accrual')->first();

    expect($je)->not->toBeNull()
        ->and(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('5130'))->toEqualWithDelta(10000.0, 0.01)
        ->and($settlement->fresh()->status->value)->toBe('approved');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Offboarding/ApproveSettlementPostsAccrualTest.php`
Expected: FAIL — accrual not posted (old path waives without GL).

- [ ] **Step 3: Inject the service + call it**

In `app/Services/Offboarding/OffboardingService.php`:

Add the import:

```php
use App\Services\Offboarding\SettlementPostingService;
```

Add the dependency to the constructor:

```php
    public function __construct(
        private readonly FinalSettlementCalculator $calculator,
        private readonly SequenceService $sequences,
        private readonly SettlementPostingService $settlementPosting,
    ) {}
```

In `approveSettlement`, replace the `closeOutstandingLoans` call:

```php
            // Recognise the settlement in the GL and clear the leaver's loans against it.
            $this->settlementPosting->postAccrual($settlement, $approver);
```

- [ ] **Step 4: Remove the dead method**

Delete the entire `private function closeOutstandingLoans(FinalSettlement $settlement): void { ... }` method (its waiving logic now lives in `SettlementPostingService::applyClearing`). Remove the now-unused `LoanRepayment`/`LoanStatus`/`LoanRepaymentStatus` imports **only if** no longer referenced elsewhere in the file (check first — `outstandingLoanBalance` still uses `LoanAccount`/`LoanStatus`; leave those).

- [ ] **Step 5: Run the test + the offboarding suite**

Run: `php artisan test tests/Feature/Offboarding/ApproveSettlementPostsAccrualTest.php`
Expected: PASS.

Run: `php artisan test tests/Feature/Offboarding`
Expected: PASS — existing off-boarding tests still green (approve still flips status, dual-control still enforced). If a prior test asserted loans are waived on a zero-gross settlement, it must be updated to the new contract (zero-gross no longer waives) — note any such change in the commit.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Offboarding/OffboardingService.php tests/Feature/Offboarding/ApproveSettlementPostsAccrualTest.php
git commit -m "feat(finance): approveSettlement posts GL accrual + clears loans via SettlementPostingService"
```

- [ ] **Step 7: Regression gate**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Offboarding`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — allow only the known `KioskRecentTest` time-of-day flake if it is the sole failure. Investigate any other red.

Run: `php artisan migrate:fresh --seed`
Expected: completes (the new 5130 account + slugs seed cleanly).

```bash
git commit --allow-empty -m "test(finance): settlement S-1 accrual + loan clearing + subledger regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Balanced by construction**: `DR gross = paye + (garn+other) + loanCleared + netPay`, because `netPay = gross − paye − (garn+other) − loanCleared`. Zero legs are omitted (PostingLine forbids zero amounts; PostingDocument needs ≥2 lines — the DR plus at least one CR always satisfies this for `gross > 0`).
- **Loan split is exact**: principal→1300, interest→4600 come from the cleared installments' `principal_portion`/`interest_portion`, so 1300 nets to exactly the principal removed and the subledger (now excluding Waived) drops by the same principal.
- **Shortfall leaves debt live**: uncovered installments stay `Scheduled` (counted by the subledger, still owed); the loan stays `Repaying`.
- **Idempotent**: re-posting hits the `(FinalSettlement, id, 'accrual')` unique index and returns the existing JE; the upstream status guard (`Calculated → Approved` only) already prevents re-entry.
- **Actor-less safety**: posting resolves an actor via `PostingActorResolver`; tests seed a `super_admin` fallback.
- **S-2** adds the payment leg (DR 2300 / CR payroll bank) + the mark-paid action + UI; this plan deliberately leaves `net_payable` sitting in 2300 as a correct open liability.
```
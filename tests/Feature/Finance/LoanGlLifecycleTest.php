<?php

use App\Enums\AmortizationMethod;
use App\Enums\IdentityVerificationStatus;
use App\Enums\LoanProductType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Models\AttendanceSummary;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\LoanService;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

/**
 * Closes a coverage gap: no test verified the full GL lifecycle of an
 * INTEREST-BEARING staff loan (disburse → repay via payroll over its full
 * term → Loan Receivable nets to zero and Interest Income accrues).
 *
 * We assert the robust GL invariants (not fragile per-installment math):
 *   - 1300 (Loans Receivable) natural balance == principal after disbursement
 *   - 1300 ≈ 0 after all installments are repaid through payroll
 *   - 4600 (Interest Income) ≈ loan.total_interest
 *   - loan status flips to PaidOff
 */
beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\OrgBankAccountSeeder())->run();

    $dept  = Department::factory()->create();
    $grade = Grade::create(['code' => 'GS-12', 'name' => 'Senior Officer', 'level' => 12, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create(['grade_id' => $grade->id, 'step' => 1, 'base_salary' => 5_000, 'currency' => 'GHS', 'effective_from' => '2026-01-01']);

    $this->creator  = User::factory()->create(['role' => 'hr_admin']);
    $this->approver = User::factory()->create(['role' => 'finance_officer']);
    $this->employee = Employee::factory()->create([
        'department_id'    => $dept->id,
        'current_grade_id' => $grade->id,
        'current_step'     => 1,
        'status'           => 'active',
    ]);

    IdentityVerification::create([
        'employee_id'       => $this->employee->id,
        'provider'          => 'manual_upload',
        'ghana_card_number' => 'GHA-123456789-1',
        'ghana_card_hash'   => IdentityVerification::hashCardNumber('GHA-123456789-1'),
        'status'            => IdentityVerificationStatus::Verified->value,
        'verified_at'       => now(),
        'expires_at'        => now()->addYear(),
    ]);

    // The loan runs June, July, August 2026 — the employee needs an attendance
    // summary in EACH payroll month so the ghost-worker gate doesn't skip them.
    foreach ([6, 7, 8] as $month) {
        AttendanceSummary::create([
            'employee_id'    => $this->employee->id,
            'summary_date'   => CarbonImmutable::create(2026, $month, 1),
            'status'         => 'present',
            'hours_worked'   => 8,
            'overtime_hours' => 0,
        ]);
    }

    // INTEREST-BEARING product: 12% annual, reducing-balance, short 3-month term.
    $product = LoanProduct::create([
        'code' => 'TST-IB-001', 'name' => 'Interest-Bearing Test',
        'type' => LoanProductType::Personal->value,
        'min_amount' => 1_000, 'max_amount' => 50_000,
        'min_term_months' => 1, 'max_term_months' => 36,
        'annual_interest_rate' => 0.12,
        'amortization_method'  => AmortizationMethod::ReducingBalance->value,
        'is_active' => true,
        'effective_from' => '2026-01-01',
        'approvals_required' => 2,
    ]);

    /** @var LoanService $loans */
    $loans = app(LoanService::class);
    $loan  = $loans->apply($this->employee, $product, 3_000, 3, null, $this->creator);
    $loan  = $loans->approve($loan, $this->approver);  // different user → dual-control OK
    $loan  = $loans->disburse($loan, $this->approver, CarbonImmutable::create(2026, 6, 1));

    $this->loan = $loan;
});

/** Natural balance of a GL account by its code. */
function balanceOf(string $code): float
{
    return (float) GlAccountBalance::where(
        'gl_account_id',
        GlAccount::where('code', $code)->value('id')
    )->value('balance');
}

it('recovers an interest-bearing loan to zero and accrues interest income across its full term', function () {
    // Guard: the product MUST be interest-bearing for this test to be meaningful.
    expect((float) $this->loan->total_interest)->toBeGreaterThan(0.0);

    // 1) After disbursement, Loans Receivable == principal.
    expect(balanceOf('1300'))->toEqualWithDelta(3_000.0, 0.01);

    /** @var PayrollService $svc */
    $svc = app(PayrollService::class);

    // 2) Run one payroll per loan month; each approval posts that month's
    //    repayment (1300 principal_portion credited, 4600 interest_portion credited).
    foreach ([6, 7, 8] as $month) {
        $run = $svc->createDraft(2026, $month, null, $this->creator);
        $run = $svc->calculate($run);
        $svc->approve($run, $this->approver);
    }

    // 3) Invariants after full repayment.
    expect(balanceOf('1300'))->toEqualWithDelta(0.0, 0.01); // fully recovered

    // Interest Income is an income account → natural balance = credit - debit (positive).
    expect(balanceOf('4600'))->toEqualWithDelta((float) $this->loan->fresh()->total_interest, 0.01);

    $loan = $this->loan->fresh();
    expect($loan->status)->toBe(LoanStatus::PaidOff);

    // Sanity: every scheduled repayment is marked Paid.
    $loan->repayments->each(function ($r) {
        expect($r->status)->toBe(LoanRepaymentStatus::Paid);
    });
});

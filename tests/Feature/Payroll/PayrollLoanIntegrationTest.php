<?php

use App\Enums\AmortizationMethod;
use App\Enums\IdentityVerificationStatus;
use App\Enums\LoanProductType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\LoanService;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);

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

    // Provide some attendance so the payroll gate doesn't skip this employee.
    // (We cheat by stubbing a present-day summary directly.)
    \App\Models\AttendanceSummary::create([
        'employee_id'  => $this->employee->id,
        'summary_date' => CarbonImmutable::create(2026, 6, 1),
        'status'       => 'present',
        'hours_worked' => 8,
        'overtime_hours' => 0,
    ]);

    // Book and disburse a small loan, first repayment due in the payroll period.
    $product = LoanProduct::create([
        'code' => 'TST-001', 'name' => 'Test',
        'type' => LoanProductType::Personal->value,
        'min_amount' => 100, 'max_amount' => 50_000,
        'min_term_months' => 1, 'max_term_months' => 24,
        'annual_interest_rate' => 0,
        'amortization_method'  => AmortizationMethod::StraightLine->value,
        'is_active' => true,
        'effective_from' => '2026-01-01',
        'approvals_required' => 2,
    ]);

    /** @var LoanService $loans */
    $loans = app(LoanService::class);
    $loan  = $loans->apply($this->employee, $product, 1_200, 6, null, $this->approver);
    $loan  = $loans->approve($loan, $this->creator);     // different user → dual-control OK
    $loan  = $loans->disburse($loan, $this->approver, CarbonImmutable::create(2026, 6, 1));

    $this->loan = $loan;
});

it('deducts the loan installment from net pay and marks the repayment paid on approval', function () {
    /** @var PayrollService $svc */
    $svc = app(PayrollService::class);

    $run = $svc->createDraft(2026, 6, null, $this->creator);
    $run = $svc->calculate($run);
    $line = $run->lines()->where('employee_id', $this->employee->id)->first();

    // 200/month installment was applied as a voluntary deduction
    expect((float) $line->voluntary_deductions)->toBeGreaterThanOrEqual(200.0);

    // Approve to finalize the loan repayment
    $svc->approve($run, $this->approver);

    $repayment = $this->loan->repayments()->where('installment_no', 1)->first();
    expect($repayment->status)->toBe(LoanRepaymentStatus::Paid);
    expect((float) $repayment->paid_amount)->toBe(200.0);

    $this->loan->refresh();
    expect((float) $this->loan->outstanding_balance)->toBe(1_000.0); // 1200 - 200
    expect($this->loan->status)->toBe(LoanStatus::Repaying);
});

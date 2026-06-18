<?php

use App\Enums\AmortizationMethod;
use App\Enums\LoanProductType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Models\Employee;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Services\Loans\LoanService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\OrgBankAccountSeeder())->run();

    $this->product = LoanProduct::create([
        'code' => 'TST-001', 'name' => 'Test Personal',
        'type' => LoanProductType::Personal->value,
        'min_amount' => 1_000, 'max_amount' => 50_000,
        'min_term_months' => 3, 'max_term_months' => 36,
        'annual_interest_rate' => 0.12,
        'amortization_method'  => AmortizationMethod::ReducingBalance->value,
        'is_active' => true,
        'effective_from' => '2026-01-01',
        'approvals_required' => 2,
    ]);

    $this->applicant = User::factory()->create(['role' => 'employee']);
    $this->approver  = User::factory()->create(['role' => 'finance_officer']);
    $this->employee  = Employee::factory()->create(['user_id' => $this->applicant->id]);

    $this->svc = app(LoanService::class);
});

it('books a loan with snapshotted terms at application', function () {
    $loan = $this->svc->apply($this->employee, $this->product, 10_000, 12, 'Test', $this->applicant);

    expect($loan->status)->toBe(LoanStatus::PendingApproval);
    expect((float) $loan->principal)->toBe(10_000.0);
    expect((float) $loan->booked_interest_rate)->toEqualWithDelta(0.12, 0.0001);
    expect($loan->monthly_installment)->toBeNumeric();
    expect($loan->reference)->toStartWith('LOAN-');
});

it('enforces dual-control on approval', function () {
    $loan = $this->svc->apply($this->employee, $this->product, 5_000, 6, null, $this->applicant);

    expect(fn () => $this->svc->approve($loan, $this->applicant))
        ->toThrow(DomainException::class, 'Dual-control');
});

it('generates a full repayment schedule on disbursement', function () {
    $loan = $this->svc->apply($this->employee, $this->product, 6_000, 6, null, $this->applicant);
    $loan = $this->svc->approve($loan, $this->approver);
    $loan = $this->svc->disburse($loan, $this->approver, CarbonImmutable::create(2026, 7, 1));

    expect($loan->status)->toBe(LoanStatus::Disbursed);
    expect($loan->repayments)->toHaveCount(6);
    expect($loan->repayments->first()->due_period->format('Y-m'))->toBe('2026-07');
    expect($loan->repayments->last()->due_period->format('Y-m'))->toBe('2026-12');
});

it('decrements balance and flips to paid_off after all repayments post', function () {
    $loan = $this->svc->apply($this->employee, $this->product, 3_000, 3, null, $this->applicant);
    $loan = $this->svc->approve($loan, $this->approver);
    $loan = $this->svc->disburse($loan, $this->approver);

    // A real payroll_run row so the FK on loan_repayments.payroll_run_id holds.
    $run = \App\Models\PayrollRun::create([
        'reference'    => 'PR-2026-07-TEST',
        'period_year'  => 2026, 'period_month' => 7,
        'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        'status'       => \App\Enums\PayrollRunStatus::Calculated->value,
        'created_by'   => $this->approver->id,
    ]);

    foreach ($loan->repayments()->orderBy('installment_no')->get() as $r) {
        $this->svc->postRepayment($r, payrollRunId: $run->id);
    }

    $loan = $loan->fresh();
    expect($loan->status)->toBe(LoanStatus::PaidOff);
    expect((float) $loan->outstanding_balance)->toBe(0.0);
    expect((int) $loan->installments_paid)->toBe(3);
});

it('rejects an application with invalid principal', function () {
    expect(fn () => $this->svc->apply($this->employee, $this->product, 99, 6, null, $this->applicant))
        ->toThrow(DomainException::class, 'outside product limits');
});

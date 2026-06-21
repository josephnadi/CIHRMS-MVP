<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\LoanAccount;
use App\Models\OffboardingCase;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Database\QueryException;

// Hard-deleting an employee / offboarding case must be REFUSED while financial
// records reference it — those rows are evidence and must never cascade away.

function payrollLineFor(Employee $e): PayrollLine
{
    $run = PayrollRun::create([
        'reference' => 'PR-' . uniqid(), 'period_year' => 2026, 'period_month' => 5,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'status' => 'calculated',
    ]);

    return PayrollLine::create([
        'payroll_run_id' => $run->id, 'employee_id' => $e->id,
        'basic' => 5000, 'allowance_total' => 0, 'gross' => 5000,
        'ssnit_base' => 5000, 'ssnit_tier1_employee' => 275, 'ssnit_tier1_employer' => 650,
        'nhia_split' => 125, 'tier2_employer' => 250, 'tier3_employee' => 0,
        'paye' => 600, 'voluntary_deductions' => 0, 'net' => 4125, 'status' => 'calculated',
    ]);
}

it('refuses to hard-delete an employee that has payroll lines', function () {
    $e = Employee::factory()->create();
    payrollLineFor($e);

    expect(fn () => $e->forceDelete())->toThrow(QueryException::class);
    expect(Employee::withTrashed()->find($e->id))->not->toBeNull();
});

it('refuses to hard-delete an employee that has a loan account', function () {
    $e = Employee::factory()->create();
    LoanAccount::factory()->create(['employee_id' => $e->id]);

    expect(fn () => $e->forceDelete())->toThrow(QueryException::class);
});

it('refuses to hard-delete an offboarding case that has a final settlement', function () {
    $e    = Employee::factory()->create();
    $case = OffboardingCase::create([
        'reference'                  => 'OFF-' . uniqid(),
        'employee_id'                => $e->id,
        'status'                     => 'draft',
        'exit_type'                  => 'resignation',
        'notice_received_on'         => '2026-05-01',
        'last_working_day'           => '2026-05-31',
        'effective_termination_date' => '2026-05-31',
    ]);
    FinalSettlement::create([
        'offboarding_case_id' => $case->id, 'status' => 'calculated',
        'basic_salary' => 5000, 'years_of_service' => 4.5, 'accrued_leave_days' => 10,
        'gross_settlement' => 1000, 'total_deductions' => 0, 'net_payable' => 1000,
    ]);

    expect(fn () => $case->forceDelete())->toThrow(QueryException::class);
});

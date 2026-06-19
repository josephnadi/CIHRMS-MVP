<?php

declare(strict_types=1);

use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\OffboardingCase;
use App\Models\User;

it('persists a disbursement linked to a settlement with no payroll run/line', function () {
    $employee = Employee::factory()->create();
    $case = OffboardingCase::create([
        'reference' => 'OFF-D-' . uniqid(), 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'exit_type' => 'resignation',
        'status' => 'awaiting_settlement', 'notice_received_on' => '2026-06-01',
        'last_working_day' => '2026-06-30', 'effective_termination_date' => '2026-06-30',
    ]);
    $settlement = FinalSettlement::create([
        'offboarding_case_id' => $case->id, 'status' => 'paid',
        'basic_salary' => 2000, 'years_of_service' => 1, 'accrued_leave_days' => 0, 'working_days_per_month' => 22,
        'gratuity' => 5000, 'severance' => 0, 'leave_encashment' => 0, 'prorated_13th_month' => 0, 'ex_gratia' => 0,
        'gross_settlement' => 5000, 'outstanding_loans' => 0, 'garnishments' => 0, 'other_deductions' => 0,
        'total_deductions' => 0, 'paye_on_settlement' => 0, 'net_payable' => 5000,
        'calculated_by' => User::factory()->create()->id, 'calculated_at' => now(), 'breakdown' => [],
    ]);

    $d = Disbursement::create([
        'final_settlement_id' => $settlement->id,
        'payroll_run_id' => null, 'payroll_line_id' => null,
        'employee_id' => $employee->id, 'channel' => 'ghipss_ach', 'status' => 'pending',
        'gross_amount' => 5000, 'e_levy' => 0, 'provider_fee' => 0, 'net_to_recipient' => 5000,
        'beneficiary_account' => '000123', 'beneficiary_name' => 'Leaver',
    ]);

    expect($d->fresh()->final_settlement_id)->toBe($settlement->id)
        ->and($d->finalSettlement->id)->toBe($settlement->id)
        ->and($d->payroll_run_id)->toBeNull();
});

<?php

declare(strict_types=1);

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Events\PayrollRunApproved;
use App\Listeners\MaterialiseDisbursements;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayoutBatch;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('materialises disbursements into a pending_release batch on payroll approval (no auto-send)', function () {
    $approver = User::factory()->create(['role' => 'super_admin']);
    $dept     = Department::factory()->create();

    $employee = Employee::factory()->create([
        'department_id'        => $dept->id,
        'disbursement_channel' => DisbursementChannel::MtnMomo->value,
        'mobile_money_number'  => '0244000001',
    ]);

    $run = PayrollRun::create([
        'reference'    => 'PR-2026-06-WIRE',
        'period_year'  => 2026, 'period_month' => 6,
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'status'       => 'calculated',
        'approved_by'  => $approver->id,
    ]);

    PayrollLine::create([
        'payroll_run_id' => $run->id,
        'employee_id'    => $employee->id,
        'basic' => 5000, 'allowance_total' => 0, 'gross' => 5000,
        'ssnit_base' => 5000, 'ssnit_tier1_employee' => 275, 'ssnit_tier1_employer' => 650,
        'nhia_split' => 125, 'tier2_employer' => 250, 'tier3_employee' => 0,
        'paye' => 600, 'voluntary_deductions' => 0,
        'net' => 4125,
        'status' => 'calculated',
    ]);

    app(MaterialiseDisbursements::class)->handle(new PayrollRunApproved($run));

    $batch = PayoutBatch::where('source_type', PayrollRun::class)->where('source_id', $run->id)->first();

    expect($batch)->not->toBeNull()
        ->and($batch->status)->toBe(PayoutBatchStatus::PendingRelease)
        ->and($batch->disbursements()->where('status', DisbursementStatus::Pending->value)->count())
            ->toBeGreaterThan(0);
    // nothing was sent — all rows still Pending
    expect($batch->disbursements()->where('status', DisbursementStatus::Sent->value)->count())->toBe(0);
});

<?php

use App\Enums\IdentityVerificationStatus;
use App\Enums\PayrollRunStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Payroll\PayrollService;
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

    // Identity-verify the employee so they pass the payroll gate
    IdentityVerification::create([
        'employee_id'     => $this->employee->id,
        'provider'        => 'manual_upload',
        'ghana_card_number' => 'GHA-123456789-1',
        'ghana_card_hash' => IdentityVerification::hashCardNumber('GHA-123456789-1'),
        'status'          => IdentityVerificationStatus::Verified->value,
        'verified_at'     => now(),
        'expires_at'      => now()->addYear(),
    ]);
});

it('creates a draft, calculates, and totals roll up correctly', function () {
    /** @var PayrollService $svc */
    $svc = app(PayrollService::class);

    $run = $svc->createDraft(2026, 6, null, $this->creator);
    expect($run->status)->toBe(PayrollRunStatus::Draft);

    $run = $svc->calculate($run);

    expect($run->status)->toBe(PayrollRunStatus::Calculated);
    expect($run->lines_count)->toBe(1);
    expect((float) $run->gross_total)->toBeGreaterThan(0);
    expect((float) $run->ssnit_tier1_employee_total)->toEqualWithDelta(275.0, 0.01); // 5.5% of 5000
    expect((float) $run->ssnit_tier1_employer_total)->toEqualWithDelta(650.0, 0.01); // 13% of 5000
    expect((float) $run->nhia_total)->toEqualWithDelta(125.0, 0.01);                  // 2.5% of 5000
});

it('skips employees without a verified Ghana Card', function () {
    $other = Employee::factory()->create([
        'department_id'    => $this->employee->department_id,
        'current_grade_id' => $this->employee->current_grade_id,
        'current_step'     => 1,
        'status'           => 'active',
    ]);

    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));

    expect($run->lines_count)->toBe(1);
    expect($run->skipped_count)->toBe(1);
});

it('enforces dual-control: creator cannot approve their own run', function () {
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));

    expect(fn () => $svc->approve($run, $this->creator))
        ->toThrow(\DomainException::class, 'Dual-control');
});

it('allows a different user to approve a calculated run', function () {
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));

    $run = $svc->approve($run, $this->approver);
    expect($run->status)->toBe(PayrollRunStatus::Approved);
    expect($run->approved_by)->toBe($this->approver->id);
});

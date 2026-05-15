<?php

use App\Enums\AttendanceSource;
use App\Enums\IdentityVerificationStatus;
use App\Enums\PayrollRunStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\GhanaPublicHolidaySeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->seed(GhanaPublicHolidaySeeder::class);

    $dept  = Department::factory()->create();
    $grade = Grade::create(['code' => 'GS-12', 'name' => 'Senior Officer', 'level' => 12, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create(['grade_id' => $grade->id, 'step' => 1, 'base_salary' => 5_000, 'currency' => 'GHS', 'effective_from' => '2026-01-01']);

    $this->creator = User::factory()->create(['role' => 'hr_admin']);

    $this->employee = Employee::factory()->create([
        'department_id'    => $dept->id,
        'current_grade_id' => $grade->id,
        'current_step'     => 1,
        'status'           => 'active',
    ]);

    // Identity verified
    IdentityVerification::create([
        'employee_id'       => $this->employee->id,
        'provider'          => 'manual_upload',
        'ghana_card_number' => 'GHA-123456789-1',
        'ghana_card_hash'   => IdentityVerification::hashCardNumber('GHA-123456789-1'),
        'status'            => IdentityVerificationStatus::Verified->value,
        'verified_at'       => now(),
        'expires_at'        => now()->addYear(),
    ]);
});

it('skips an identity-verified employee with zero attendance in period', function () {
    $svc = app(PayrollService::class);

    $run = $svc->createDraft(2026, 6, null, $this->creator);
    $run = $svc->calculate($run);

    expect($run->skipped_count)->toBe(1);
    expect($run->lines_count)->toBe(0);

    $line = $run->lines()->first();
    expect($line->status)->toBe('skipped');
    expect($line->skip_reason)->toContain('potential ghost worker');
});

it('pays an employee who clocked in at least one day in the period', function () {
    // Record a clock-in on a working day in June 2026
    $att = app(AttendanceService::class);
    $att->record($this->employee, CarbonImmutable::parse('2026-06-03 08:00'), 'in',  AttendanceSource::Biometric);
    $att->record($this->employee, CarbonImmutable::parse('2026-06-03 17:00'), 'out', AttendanceSource::Biometric);

    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));

    expect($run->lines_count)->toBe(1);
    expect($run->skipped_count)->toBe(0);
    expect((float) $run->gross_total)->toBeGreaterThan(0);
});

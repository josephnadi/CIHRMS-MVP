<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\IdentityVerificationStatus;
use App\Models\AttendanceSummary;
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
    // GS-10: basic salary = 1,733.30 → hourly rate ≈ 10.00 at 173.33 h/month
    $grade = Grade::create(['code' => 'GS-10', 'name' => 'Officer', 'level' => 10, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create([
        'grade_id'       => $grade->id,
        'step'           => 1,
        'base_salary'    => 1733.30,
        'currency'       => 'GHS',
        'effective_from' => '2026-01-01',
    ]);

    $this->creator = User::factory()->create(['role' => 'hr_admin']);

    $this->employee = Employee::factory()->create([
        'department_id'    => $dept->id,
        'current_grade_id' => $grade->id,
        'current_step'     => 1,
        'status'           => 'active',
    ]);

    // Identity verified so the payroll gate passes
    IdentityVerification::create([
        'employee_id'       => $this->employee->id,
        'provider'          => 'manual_upload',
        'ghana_card_number' => 'GHA-111111111-1',
        'ghana_card_hash'   => IdentityVerification::hashCardNumber('GHA-111111111-1'),
        'status'            => IdentityVerificationStatus::Verified->value,
        'verified_at'       => now(),
        'expires_at'        => now()->addYear(),
    ]);

    // Record one regular working day so the attendance gate passes
    $att = app(AttendanceService::class);
    $att->record($this->employee, CarbonImmutable::parse('2026-05-04 08:00'), 'in',  AttendanceSource::Biometric);
    $att->record($this->employee, CarbonImmutable::parse('2026-05-04 17:00'), 'out', AttendanceSource::Biometric);
});

it('adds overtime pay to gross when AttendanceSummary has overtime_hours in the pay period', function () {
    // Directly set 3 overtime hours on the day already recorded
    AttendanceSummary::where('employee_id', $this->employee->id)
        ->where('summary_date', '2026-05-04')
        ->update(['overtime_hours' => 3.00]);

    /** @var PayrollService $svc */
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 5, null, $this->creator));

    $line = $run->lines()->where('status', 'calculated')->firstOrFail();

    expect((float) $line->overtime_hours)->toBe(3.0);
    expect((float) $line->overtime_pay)->toBeGreaterThan(0.0);

    // hourly = 1733.30 / 173.33 ≈ 10.00; OT pay ≈ 30.00
    expect((float) $line->overtime_pay)->toEqualWithDelta(30.0, 0.05);

    // gross must be base_gross + overtime_pay
    $baseGross = (float) $line->basic + (float) $line->allowance_total - (float) $line->overtime_pay;
    expect((float) $line->gross)->toEqualWithDelta($baseGross + (float) $line->overtime_pay, 0.01);

    // breakdown should carry the overtime snapshot
    expect($line->breakdown['overtime']['hours'])->toBe(3.0);
    expect($line->breakdown['overtime']['pay'])->toEqualWithDelta(30.0, 0.05);
});

it('leaves overtime_pay at zero when no overtime hours are recorded', function () {
    /** @var PayrollService $svc */
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 5, null, $this->creator));

    $line = $run->lines()->where('status', 'calculated')->firstOrFail();

    expect((float) $line->overtime_hours)->toBe(0.0);
    expect((float) $line->overtime_pay)->toBe(0.0);
});

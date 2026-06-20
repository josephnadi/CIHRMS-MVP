<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\IdentityVerificationStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\PayrollLine;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->svc = app(PayrollService::class);
});

/**
 * Build an active, identity-verified employee with one recorded attendance day in
 * the June 2026 period so PayrollService::calculate produces a real (non-skipped)
 * line. A grade/step yields a stable non-zero basic for chargeable/PAYE/Tier-3.
 */
function makePayableEmployee(float $tier3Rate): Employee
{
    $dept  = Department::factory()->create();
    $grade = Grade::create([
        'code' => 'GS-' . fake()->unique()->numberBetween(1, 9999),
        'name' => 'Officer', 'level' => 10, 'min_step' => 1, 'max_step' => 8,
    ]);
    GradeStep::create([
        'grade_id' => $grade->id, 'step' => 1, 'base_salary' => 6_000,
        'currency' => 'GHS', 'effective_from' => '2026-01-01',
    ]);

    $employee = Employee::factory()->active()->create([
        'department_id'    => $dept->id,
        'current_grade_id' => $grade->id,
        'current_step'     => 1,
        'tier3_rate'       => $tier3Rate,
    ]);

    IdentityVerification::create([
        'employee_id'       => $employee->id,
        'provider'          => 'manual_upload',
        'ghana_card_number' => 'GHA-' . fake()->unique()->numerify('#########') . '-1',
        'ghana_card_hash'   => IdentityVerification::hashCardNumber('GHA-' . fake()->unique()->numerify('#########') . '-1'),
        'status'            => IdentityVerificationStatus::Verified->value,
        'verified_at'       => now(),
        'expires_at'        => now()->addYear(),
    ]);

    $att = app(AttendanceService::class);
    $att->record($employee, CarbonImmutable::parse('2026-06-03 08:00'), 'in',  AttendanceSource::Biometric);
    $att->record($employee, CarbonImmutable::parse('2026-06-03 17:00'), 'out', AttendanceSource::Biometric);

    return $employee;
}

/** Run a single-employee payroll for an employee and return their calculated line. */
function runLineFor(Employee $employee): PayrollLine
{
    $svc = app(PayrollService::class);
    $run = $svc->createDraft(2026, 6, $employee->department_id, User::factory()->create());
    $svc->calculate($run->fresh());

    return PayrollLine::where('payroll_run_id', $run->id)
        ->where('employee_id', $employee->id)
        ->firstOrFail();
}

it('deducts Tier-3, lowers PAYE via relief, and reduces net for an enrolled employee', function () {
    $base     = makePayableEmployee(0.0);
    $enrolled = makePayableEmployee(0.05);

    $baseLine     = runLineFor($base);
    $enrolledLine = runLineFor($enrolled);

    // Tier-3 elected = 5% of basic; net pay drops by at least the Tier-3 amount; PAYE is lower (relief).
    expect((float) $enrolledLine->tier3_employee)->toBeGreaterThan(0.0)
        ->and((float) $baseLine->tier3_employee)->toBe(0.0)
        ->and((float) $enrolledLine->paye)->toBeLessThan((float) $baseLine->paye)   // relief lowered chargeable
        ->and((float) $enrolledLine->net)->toBeLessThan((float) $baseLine->net);     // Tier-3 left net pay
});

<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\IdentityVerificationStatus;
use App\Enums\StatutoryReturnKind;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlAccountBalance;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\PensionTrustee;
use App\Models\StatutoryReturn;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // actor-less posting fallback
});

/**
 * Build an active, identity-verified employee with one recorded attendance day in
 * the June 2026 period so PayrollService::calculate produces a real (non-skipped)
 * line that carries a non-zero Tier-3 deduction.
 */
function makeTier3PayableEmployee(Department $dept, PensionTrustee $trustee, float $tier3Rate): Employee
{
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
        'tier3_trustee_id' => $trustee->id,
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

it('credits GL 2230 and generates a Tier-3 schedule on approval', function () {
    $svc     = app(PayrollService::class);
    $dept    = Department::factory()->create();
    $trustee = PensionTrustee::create([
        'name'                => 'Acme Master Trust',
        'npra_license_number' => 'NPRA-T3-001',
        'is_active'           => true,
    ]);
    makeTier3PayableEmployee($dept, $trustee, 0.05);

    $run = $svc->calculate($svc->createDraft(2026, 6, $dept->id, User::factory()->create())->fresh());
    $approver = User::factory()->create(['role' => 'super_admin']);
    $svc->approve($run->fresh(), $approver);

    // GL 2230 (Tier-3 payable) credited by tier3_total.
    $tier3Gl = (float) GlAccountBalance::query()
        ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
        ->where('gl_accounts.code', '2230')
        ->value('gl_account_balances.balance');

    expect($tier3Gl)->toBeGreaterThan(0.0)
        ->and((float) $run->fresh()->tier3_total)->toEqualWithDelta($tier3Gl, 0.01);

    // A Tier-3 statutory schedule was generated.
    expect(
        StatutoryReturn::where('payroll_run_id', $run->id)
            ->where('kind', StatutoryReturnKind::Tier3->value)
            ->exists()
    )->toBeTrue();
});

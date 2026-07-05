<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\IdentityVerificationStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\BackPayService;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\SalaryRevisionService;
use Carbon\CarbonImmutable;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();

    $dept  = Department::factory()->create();
    $this->grade = Grade::create(['code' => 'BP', 'name' => 'Officer', 'level' => 8, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create(['grade_id' => $this->grade->id, 'step' => 1, 'base_salary' => 8_151.72, 'currency' => 'GHS', 'effective_from' => '2025-01-01']);

    $this->creator  = User::factory()->create(['role' => 'hr_admin']);
    $this->approver = User::factory()->create(['role' => 'finance_officer']);

    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id, 'current_grade_id' => $this->grade->id,
        'current_step' => 1, 'status' => 'active', 'tier3_rate' => 0,
    ]);
    IdentityVerification::create([
        'employee_id' => $this->employee->id, 'provider' => 'manual_upload',
        'ghana_card_number' => 'GHA-000000009-1', 'ghana_card_hash' => IdentityVerification::hashCardNumber('GHA-000000009-1'),
        'status' => IdentityVerificationStatus::Verified->value, 'verified_at' => now(), 'expires_at' => now()->addYear(),
    ]);
    $att = app(AttendanceService::class);
    $att->record($this->employee, CarbonImmutable::parse('2026-04-03 08:00'), 'in',  AttendanceSource::Biometric);
    $att->record($this->employee, CarbonImmutable::parse('2026-04-03 16:00'), 'out', AttendanceSource::Biometric);
});

it('computes arrears + back-PAYE for a retroactive revision', function () {
    // 1. April 2026 run is calculated + approved at the OLD rate (8,151.72).
    $payroll = app(PayrollService::class);
    $run = $payroll->approve($payroll->calculate($payroll->createDraft(2026, 4, null, $this->creator)), $this->approver);

    // 2. A 10% revision is then applied, effective from 1 April 2026 (retroactive).
    $revision = app(SalaryRevisionService::class)->apply(10.0, '2026-04-01', 'institute', [], $this->creator);
    $newBasic = round(8_151.72 * 1.10, 2); // 8,966.89

    // 3. Back-pay picks up the April run and computes the delta.
    $result = app(BackPayService::class)->computeForRevision($revision);

    expect($result)->toHaveCount(1);
    $emp = $result[0];
    expect($emp['employee_id'])->toBe($this->employee->id)
        ->and($emp['months'])->toHaveCount(1)
        ->and($emp['months'][0]['old_basic'])->toBe(8_151.72)
        ->and($emp['months'][0]['new_basic'])->toBe($newBasic);

    // Invariant (no allowances, no Tier-3): the gross uplift (new−old) is split
    // between extra SSNIT, extra PAYE (back-PAYE) and the arrears net.
    $grossUplift = round($newBasic - 8_151.72, 2);        // 815.17
    $deltaSsnit  = round(($newBasic - 8_151.72) * 0.055, 2); // 5.5% employee
    expect(round($emp['arrears_net'] + $emp['back_paye'] + $deltaSsnit, 2))->toEqualWithDelta($grossUplift, 0.02);
    expect($emp['back_paye'])->toBeGreaterThan(0.0)
        ->and($emp['arrears_net'])->toBeGreaterThan(0.0);
});

it('renders the back-pay preview endpoint', function () {
    $payroll = app(PayrollService::class);
    $payroll->approve($payroll->calculate($payroll->createDraft(2026, 4, null, $this->creator)), $this->approver);
    $revision = app(SalaryRevisionService::class)->apply(10.0, '2026-04-01', 'institute', [], $this->creator);

    $officer = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['payroll.run']]);
    $this->actingAs($officer)
        ->get(route('salary-revisions.back-pay', $revision->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Payroll/Revisions/BackPay')
            ->has('arrears', 1)
            ->where('arrears.0.employee_id', $this->employee->id));
});

it('returns nothing when no approved run precedes the revision', function () {
    // Revision effective in the future — no paid months on/after it yet.
    $revision = app(SalaryRevisionService::class)->apply(10.0, '2027-01-01', 'institute', [], $this->creator);

    expect(app(BackPayService::class)->computeForRevision($revision))->toBe([]);
});

<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\IdentityVerificationStatus;
use App\Models\Allowance;
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

// Reproduces the CIHRM "Sample PAYROLL" payslip (staff member "Ama") end-to-end:
//   Revised Basic 8,151.72 → + Fuel BIK (5%, cap 625) → Assessable
//   → − SSF 5.5% − Provident 7% → Chargeable → − PAYE → Net → + Transport 18%.
beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();

    $dept  = Department::factory()->create();
    $grade = Grade::create(['code' => 'CIHRM-A', 'name' => 'Officer', 'level' => 8, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create(['grade_id' => $grade->id, 'step' => 1, 'base_salary' => 8_151.72, 'currency' => 'GHS', 'effective_from' => '2025-01-01']);

    $this->creator = User::factory()->create(['role' => 'hr_admin']);

    $this->employee = Employee::factory()->create([
        'department_id'    => $dept->id,
        'current_grade_id' => $grade->id,
        'current_step'     => 1,
        'status'           => 'active',
        'tier3_rate'       => 0.07,   // Provident Fund 7% of basic
    ]);

    IdentityVerification::create([
        'employee_id'       => $this->employee->id,
        'provider'          => 'manual_upload',
        'ghana_card_number' => 'GHA-000000001-1',
        'ghana_card_hash'   => IdentityVerification::hashCardNumber('GHA-000000001-1'),
        'status'            => IdentityVerificationStatus::Verified->value,
        'verified_at'       => now(),
        'expires_at'        => now()->addYear(),
    ]);

    // Fuel benefit-in-kind: 5% of cash emolument, capped at GHS 625, taxable.
    Allowance::create([
        'employee_id' => $this->employee->id, 'type' => 'fuel', 'label' => 'Fuel (BIK)', 'amount' => 0,
        'calc_method' => Allowance::CALC_PERCENT_OF_EMOLUMENT, 'rate' => 0.05, 'cap' => 625,
        'is_taxable' => true, 'effective_from' => '2025-01-01',
    ]);
    // Transport refund: 18% of basic, non-taxable, added to take-home after tax.
    Allowance::create([
        'employee_id' => $this->employee->id, 'type' => 'transport', 'label' => 'Refund of Transportation', 'amount' => 0,
        'calc_method' => Allowance::CALC_PERCENT_OF_BASIC, 'rate' => 0.18,
        'is_taxable' => false, 'effective_from' => '2025-01-01',
    ]);

    $att = app(AttendanceService::class);
    $att->record($this->employee, CarbonImmutable::parse('2026-04-03 08:00'), 'in',  AttendanceSource::Biometric);
    $att->record($this->employee, CarbonImmutable::parse('2026-04-03 16:00'), 'out', AttendanceSource::Biometric);
});

it('computes the CIHRM payslip (fuel BIK, reliefs, transport refund) exactly', function () {
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 4, null, $this->creator));

    $line = PayrollLine::where('payroll_run_id', $run->id)->firstOrFail();

    // Basic
    expect((float) $line->basic)->toEqualWithDelta(8_151.72, 0.01);

    // Allowances: fuel 407.59 (taxable) + transport 1,467.31 (non-taxable)
    expect((float) $line->allowance_total)->toEqualWithDelta(1_874.90, 0.02);

    // Reliefs on basic: SSF 5.5% = 448.34, Provident (Tier-3) 7% = 570.62
    expect((float) $line->ssnit_tier1_employee)->toEqualWithDelta(448.34, 0.01)
        ->and((float) $line->tier3_employee)->toEqualWithDelta(570.62, 0.01);

    // PAYE on chargeable 7,540.35 (= 8,559.31 assessable − 448.34 − 570.62)
    expect((float) $line->paye)->toEqualWithDelta(1_483.58, 0.02);

    // Take-home = gross − SSF − Tier-3 − PAYE = 7,524.07 (matches the sample)
    expect((float) $line->net)->toEqualWithDelta(7_524.07, 0.03);
});

it('caps the fuel benefit-in-kind at GHS 625 for higher earners', function () {
    // Dr Eduku: basic 36,300 → 5% = 1,815, capped at 625.
    GradeStep::where('step', 1)->update(['base_salary' => 36_300]);

    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 4, null, $this->creator));
    $line = PayrollLine::where('payroll_run_id', $run->id)->firstOrFail();

    // fuel (625 capped) + transport (18% of 36,300 = 6,534) = 7,159 allowance total
    expect((float) $line->allowance_total)->toEqualWithDelta(7_159.00, 0.02);
    // Chargeable 32,387.50 → PAYE 8,319.92 (matches Dr Eduku's sheet)
    expect((float) $line->paye)->toEqualWithDelta(8_319.92, 0.02);
});

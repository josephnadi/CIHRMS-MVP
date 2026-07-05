<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\IdentityVerificationStatus;
use App\Enums\JournalSourceType;
use App\Models\BackPayRun;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\BackPayRunService;
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

    // April 2026 paid at the OLD rate, then a 10% retroactive revision.
    $payroll = app(PayrollService::class);
    $payroll->approve($payroll->calculate($payroll->createDraft(2026, 4, null, $this->creator)), $this->approver);
    $this->revision = app(SalaryRevisionService::class)->apply(10.0, '2026-04-01', 'institute', [], $this->creator);
});

it('snapshots arrears into a draft run with one line per employee', function () {
    $run = app(BackPayRunService::class)->create($this->revision, $this->creator);

    expect($run->status)->toBe(BackPayRun::STATUS_DRAFT)
        ->and($run->employees_count)->toBe(1)
        ->and($run->lines)->toHaveCount(1);

    $line = $run->lines->first();
    expect((float) $line->arrears_net)->toBeGreaterThan(0.0)
        ->and((float) $line->back_paye)->toBeGreaterThan(0.0)
        ->and((float) $line->ssnit_employee)->toBeGreaterThan(0.0)
        ->and($line->breakdown)->toHaveCount(1); // one affected month

    // Run totals equal the single line.
    expect((float) $run->arrears_net_total)->toBe((float) $line->arrears_net)
        ->and((float) $run->back_paye_total)->toBe((float) $line->back_paye);
});

it('refuses a second run for the same revision', function () {
    $svc = app(BackPayRunService::class);
    $svc->create($this->revision, $this->creator);

    expect(fn () => $svc->create($this->revision, $this->creator))
        ->toThrow(DomainException::class, 'already exists');
});

it('enforces dual control on approval', function () {
    $svc = app(BackPayRunService::class);
    $run = $svc->create($this->revision, $this->creator);

    expect(fn () => $svc->approve($run, $this->creator))
        ->toThrow(DomainException::class, 'Dual-control');
});

it('posts a balanced catch-up accrual on approval', function () {
    $svc = app(BackPayRunService::class);
    $run = $svc->create($this->revision, $this->creator);
    $svc->approve($run, $this->approver);

    expect($run->fresh()->status)->toBe(BackPayRun::STATUS_APPROVED);

    $entry = JournalEntry::query()
        ->where('source_type', JournalSourceType::BackPay->value)
        ->where('source_id', $run->id)
        ->where('source_purpose', 'accrual')
        ->with('lines')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->status->value ?? $entry->status)->toBe('posted');

    $debits  = round((float) $entry->lines->sum('debit_amount'), 2);
    $credits = round((float) $entry->lines->sum('credit_amount'), 2);
    expect($debits)->toEqualWithDelta($credits, 0.01);

    // The credit side splits the staff-cost debit into net + back-PAYE + statutory.
    $credited = round(
        (float) $run->arrears_net_total + (float) $run->back_paye_total
        + (float) $run->ssnit_employee_total + (float) $run->ssnit_employer_total
        + (float) $run->tier2_employer_total + (float) $run->tier3_employee_total,
        2,
    );
    expect($credits)->toEqualWithDelta($credited, 0.01);
});

it('marks an approved run paid', function () {
    $svc = app(BackPayRunService::class);
    $run = $svc->create($this->revision, $this->creator);
    $svc->approve($run, $this->approver);
    $paid = $svc->markPaid($run);

    expect($paid->status)->toBe(BackPayRun::STATUS_PAID)
        ->and($paid->paid_at)->not->toBeNull();
});

it('creates a run and lands on its detail page from the preview endpoint', function () {
    $officer = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['payroll.run']]);

    $this->actingAs($officer)
        ->post(route('salary-revisions.back-pay.run', $this->revision->id))
        ->assertRedirect();

    $run = BackPayRun::query()->where('salary_revision_id', $this->revision->id)->firstOrFail();

    $this->actingAs($officer)
        ->get(route('back-pay-runs.show', $run->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Payroll/BackPayRuns/Show')
            ->where('run.reference', $run->reference)
            ->has('lines', 1));
});

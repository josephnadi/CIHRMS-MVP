<?php

declare(strict_types=1);

use App\Enums\IdentityVerificationStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();

    $dept  = Department::factory()->create();
    $grade = Grade::create(['code' => 'GS-12', 'name' => 'Senior Officer', 'level' => 12, 'min_step' => 1, 'max_step' => 8]);
    GradeStep::create(['grade_id' => $grade->id, 'step' => 1, 'base_salary' => 5_000, 'currency' => 'GHS', 'effective_from' => '2026-01-01']);

    $this->creator  = User::factory()->create(['role' => 'hr_admin']);
    $this->approver = User::factory()->create(['role' => 'finance_officer']);
    $this->reverser = User::factory()->create(['role' => 'finance_officer']);
    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id, 'current_grade_id' => $grade->id, 'current_step' => 1, 'status' => 'active',
    ]);
    IdentityVerification::create([
        'employee_id' => $this->employee->id, 'provider' => 'manual_upload',
        'ghana_card_number' => 'GHA-123456789-1',
        'ghana_card_hash' => IdentityVerification::hashCardNumber('GHA-123456789-1'),
        'status' => IdentityVerificationStatus::Verified->value,
        'verified_at' => now(), 'expires_at' => now()->addYear(),
    ]);
    \App\Models\AttendanceSummary::create([
        'employee_id' => $this->employee->id, 'summary_date' => CarbonImmutable::create(2026, 6, 1),
        'status' => 'present', 'hours_worked' => 8, 'overtime_hours' => 0,
    ]);
});

it('reverses the accrual JE and unwinds GL balances when a run is reversed', function () {
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));
    $svc->approve($run, $this->approver);

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(5000.0);

    $svc->reverse($run->fresh(), $this->reverser, 'wrong period');

    $accrual = JournalEntry::where('source_type', JournalSourceType::Payroll->value)
        ->where('source_id', $run->id)->where('source_purpose', 'accrual')->firstOrFail();

    expect($accrual->status)->toBe(JournalEntryStatus::Reversed)
        ->and((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(0.0);
});

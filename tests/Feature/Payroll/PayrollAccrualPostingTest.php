<?php

declare(strict_types=1);

use App\Enums\AmortizationMethod;
use App\Enums\IdentityVerificationStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\LoanProductType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\IdentityVerification;
use App\Models\JournalEntry;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\LoanService;
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

function balanceOf(string $code): float
{
    $gl = GlAccount::where('code', $code)->firstOrFail();
    return (float) GlAccountBalance::where('gl_account_id', $gl->id)->value('balance');
}

it('posts a balanced accrual JE on approval with no loans', function () {
    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));
    $svc->approve($run, $this->approver);

    $je = JournalEntry::where('source_type', JournalSourceType::Payroll->value)
        ->where('source_id', $run->id)->where('source_purpose', 'accrual')->first();

    expect($je)->not->toBeNull()
        ->and($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->posted_by)->toBe($this->approver->id)
        ->and($je->isBalanced())->toBeTrue();

    expect(balanceOf('5100'))->toBe(5000.0)
        ->and(balanceOf('2200'))->toBe(925.0)
        ->and(balanceOf('2220'))->toBe(250.0)
        ->and(balanceOf('5120'))->toBe(900.0);

    expect(balanceOf('2300'))->toBe(round((float) $run->fresh()->net_total, 2));
});

it('credits Loan Receivable for loan deductions taken in payroll', function () {
    $product = LoanProduct::create([
        'code' => 'TST-001', 'name' => 'Test', 'type' => LoanProductType::Personal->value,
        'min_amount' => 100, 'max_amount' => 50_000, 'min_term_months' => 1, 'max_term_months' => 24,
        'annual_interest_rate' => 0, 'amortization_method' => AmortizationMethod::StraightLine->value,
        'is_active' => true, 'effective_from' => '2026-01-01', 'approvals_required' => 2,
    ]);
    $loans = app(LoanService::class);
    $loan = $loans->apply($this->employee, $product, 1_200, 6, null, $this->approver);
    $loan = $loans->approve($loan, $this->creator);
    $loans->disburse($loan, $this->approver, CarbonImmutable::create(2026, 6, 1));

    $svc = app(PayrollService::class);
    $run = $svc->calculate($svc->createDraft(2026, 6, null, $this->creator));
    $svc->approve($run, $this->approver);

    $je = JournalEntry::where('source_type', JournalSourceType::Payroll->value)
        ->where('source_id', $run->id)->where('source_purpose', 'accrual')->firstOrFail();

    expect($je->isBalanced())->toBeTrue()
        ->and(balanceOf('1300'))->toBe(-200.0)
        ->and(balanceOf('2250'))->toBe(0.0);
});

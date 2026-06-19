<?php

use App\Enums\ClearanceItemStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ExitType;
use App\Enums\LoanStatus;
use App\Enums\OffboardingStatus;
use App\Enums\SettlementStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    // approveSettlement now posts a GL accrual via SettlementPostingService, so the
    // chart of accounts, posting-rule map, opening balances, and an actor-less
    // posting fallback (super_admin) must exist for approval to succeed.
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);

    $dept = Department::factory()->create();
    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id,
        'status'        => EmployeeStatus::Active->value,
        'hire_date'     => '2020-01-15',
        'salary'        => 6_000,
    ]);

    LeaveBalance::create([
        'employee_id' => $this->employee->id,
        'type'        => 'annual',
        'year'        => now()->year,
        'total_days'  => 15,
        'used_days'   => 5,
    ]);

    $this->initiator = User::factory()->create(['role' => 'hr_admin']);
    $this->approver  = User::factory()->create(['role' => 'finance_officer']);

    $this->svc = app(OffboardingService::class);
});

it('initiates a case and seeds the default clearance checklist', function () {
    $case = $this->svc->initiate(
        employee:         $this->employee,
        exitType:         ExitType::Retirement,
        noticeReceivedOn: '2026-06-01',
        lastWorkingDay:   '2026-09-30',
        initiator:        $this->initiator,
        reason:           'Mandatory retirement at age 60',
    );

    expect($case->reference)->toStartWith('OFF-');
    expect($case->status)->toBe(OffboardingStatus::InProgress);
    expect($case->clearanceItems()->count())->toBeGreaterThanOrEqual(10); // template seeds ~12
    expect($case->clearanceItems()->where('status', 'pending')->count())
        ->toBe($case->clearanceItems()->count());
});

it('refuses a second open case for the same employee', function () {
    $this->svc->initiate($this->employee, ExitType::Resignation, '2026-06-01', '2026-06-30', $this->initiator);

    expect(fn () => $this->svc->initiate(
        $this->employee, ExitType::Resignation, '2026-07-01', '2026-07-31', $this->initiator,
    ))->toThrow(\DomainException::class, 'already has an open');
});

it('advances to awaiting_settlement once all required clearance items are cleared', function () {
    $case = $this->svc->initiate($this->employee, ExitType::Retirement, '2026-06-01', '2026-09-30', $this->initiator);

    foreach ($case->clearanceItems as $item) {
        $this->svc->clearItem($item, $this->initiator);
    }

    $case->refresh();
    expect($case->status)->toBe(OffboardingStatus::AwaitingSettlement);
});

it('calculates a settlement that snapshots the inputs', function () {
    $case = $this->svc->initiate($this->employee, ExitType::Retirement, '2026-06-01', '2026-09-30', $this->initiator);

    foreach ($case->clearanceItems as $item) {
        $this->svc->clearItem($item, $this->initiator);
    }

    $settlement = $this->svc->calculateSettlement($case, $this->initiator);

    expect($settlement->status)->toBe(SettlementStatus::Calculated);
    expect((float) $settlement->basic_salary)->toBe(6_000.0);
    // 10 unused annual leave days × (6000/22) ≈ 2727.27
    expect((float) $settlement->leave_encashment)->toBeGreaterThan(2_700);
    expect((float) $settlement->gratuity)->toBeGreaterThan(0);
});

it('enforces dual control on settlement approval', function () {
    $case = $this->svc->initiate($this->employee, ExitType::Retirement, '2026-06-01', '2026-09-30', $this->initiator);
    foreach ($case->clearanceItems as $i) $this->svc->clearItem($i, $this->initiator);
    $settlement = $this->svc->calculateSettlement($case, $this->initiator);

    expect(fn () => $this->svc->approveSettlement($settlement, $this->initiator))
        ->toThrow(\DomainException::class, 'Dual-control');

    $approved = $this->svc->approveSettlement($settlement, $this->approver);
    expect($approved->status)->toBe(SettlementStatus::Approved);
});

it('completes the case, terminates the employee, and writes off loans', function () {
    $case = $this->svc->initiate($this->employee, ExitType::Retirement, '2026-06-01', '2026-09-30', $this->initiator);

    foreach ($case->clearanceItems as $i) $this->svc->clearItem($i, $this->initiator);
    $settlement = $this->svc->calculateSettlement($case, $this->initiator);
    $this->svc->approveSettlement($settlement, $this->approver);

    $case = $this->svc->complete($case->fresh(), $this->initiator);

    expect($case->status)->toBe(OffboardingStatus::Completed);
    expect($case->employee->fresh()->status)->toBe(EmployeeStatus::Terminated);
});

it('blocks completion until clearance is finished AND settlement is approved', function () {
    $case = $this->svc->initiate($this->employee, ExitType::Resignation, '2026-06-01', '2026-06-30', $this->initiator);

    expect(fn () => $this->svc->complete($case, $this->initiator))
        ->toThrow(\DomainException::class, 'required clearance items still pending');
});

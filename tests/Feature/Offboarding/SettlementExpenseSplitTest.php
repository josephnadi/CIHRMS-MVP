<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\GlAccountBalance;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php'; // settlementGl helper

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

it('posts each settlement component to its own expense account', function () {
    $employee = Employee::factory()->create();
    $case = OffboardingCase::create([
        'reference' => 'OFF-SPLIT-' . uniqid(), 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'exit_type' => 'redundancy',
        'status' => 'awaiting_settlement', 'notice_received_on' => '2026-06-01',
        'last_working_day' => '2026-06-30', 'effective_termination_date' => '2026-06-30',
    ]);

    // gross 10,000 = 4000 gratuity + 3000 severance + 2000 leave + 500 13th + 500 ex-gratia
    $settlement = FinalSettlement::create([
        'offboarding_case_id' => $case->id, 'status' => 'approved',
        'basic_salary' => 2000, 'years_of_service' => 3, 'accrued_leave_days' => 0, 'working_days_per_month' => 22,
        'gratuity' => 4000, 'severance' => 3000, 'leave_encashment' => 2000,
        'prorated_13th_month' => 500, 'ex_gratia' => 500, 'gross_settlement' => 10000,
        'outstanding_loans' => 0, 'garnishments' => 0, 'other_deductions' => 0,
        'total_deductions' => 0, 'paye_on_settlement' => 0, 'net_payable' => 10000,
        'calculated_by' => User::factory()->create()->id, 'calculated_at' => now(), 'breakdown' => [],
    ]);

    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect(settlementGl('5130'))->toEqualWithDelta(4000.0, 0.01) // gratuity
        ->and(settlementGl('5131'))->toEqualWithDelta(3000.0, 0.01) // severance
        ->and(settlementGl('5132'))->toEqualWithDelta(2000.0, 0.01) // leave encashment
        ->and(settlementGl('5133'))->toEqualWithDelta(500.0, 0.01)  // 13th month
        ->and(settlementGl('5134'))->toEqualWithDelta(500.0, 0.01)  // ex-gratia
        ->and(settlementGl('2300'))->toEqualWithDelta(10000.0, 0.01); // net payable
});

it('omits zero-component expense lines (only non-zero components post)', function () {
    $employee = Employee::factory()->create();
    $case = OffboardingCase::create([
        'reference' => 'OFF-SPLIT2-' . uniqid(), 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'exit_type' => 'resignation',
        'status' => 'awaiting_settlement', 'notice_received_on' => '2026-06-01',
        'last_working_day' => '2026-06-30', 'effective_termination_date' => '2026-06-30',
    ]);

    // Leave encashment only — no gratuity/severance/13th/ex-gratia.
    $settlement = FinalSettlement::create([
        'offboarding_case_id' => $case->id, 'status' => 'approved',
        'basic_salary' => 2000, 'years_of_service' => 1, 'accrued_leave_days' => 5, 'working_days_per_month' => 22,
        'gratuity' => 0, 'severance' => 0, 'leave_encashment' => 1500,
        'prorated_13th_month' => 0, 'ex_gratia' => 0, 'gross_settlement' => 1500,
        'outstanding_loans' => 0, 'garnishments' => 0, 'other_deductions' => 0,
        'total_deductions' => 0, 'paye_on_settlement' => 0, 'net_payable' => 1500,
        'calculated_by' => User::factory()->create()->id, 'calculated_at' => now(), 'breakdown' => [],
    ]);

    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect(settlementGl('5132'))->toEqualWithDelta(1500.0, 0.01) // leave encashment posted
        ->and(settlementGl('5130'))->toEqualWithDelta(0.0, 0.01)  // gratuity untouched
        ->and(settlementGl('5131'))->toEqualWithDelta(0.0, 0.01)  // severance untouched
        ->and(settlementGl('2300'))->toEqualWithDelta(1500.0, 0.01);
});

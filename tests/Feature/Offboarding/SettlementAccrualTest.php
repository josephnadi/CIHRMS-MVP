<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\LoanAccount;
use App\Models\LoanRepayment;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // actor-less posting fallback
});

/** Natural balance of a GL account by code. */
function settlementGl(string $code): float
{
    return (float) GlAccountBalance::query()
        ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
        ->where('gl_accounts.code', $code)
        ->value('gl_account_balances.balance');
}

/**
 * Build an off-boarding case + employee + an open loan with N scheduled
 * installments (principal + interest per installment), plus a calculated
 * settlement row. Returns [$settlement, $loan].
 */
function seedSettlementWithLoan(array $opts = []): array
{
    $employee = Employee::factory()->create();
    $case = OffboardingCase::create([
        'reference' => 'OFF-T-' . uniqid(), 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'exit_type' => 'resignation',
        'status' => 'awaiting_settlement', 'notice_received_on' => '2026-06-01',
        'last_working_day' => '2026-06-30', 'effective_termination_date' => '2026-06-30',
    ]);

    $loan = LoanAccount::create([
        'reference' => 'LN-T-' . uniqid(), 'employee_id' => $employee->id,
        'status' => LoanStatus::Repaying->value, 'principal' => $opts['principal'] ?? 3000,
        'term_months' => 3, 'monthly_installment' => 1100, 'total_interest' => $opts['interest'] ?? 300,
        'total_repayable' => ($opts['principal'] ?? 3000) + ($opts['interest'] ?? 300),
        'disbursed_amount' => $opts['principal'] ?? 3000,
        'outstanding_balance' => $opts['outstanding'] ?? 3300,
    ]);
    // 3 installments: principal 1000 + interest 100 each (defaults)
    for ($i = 1; $i <= 3; $i++) {
        LoanRepayment::create([
            'loan_account_id' => $loan->id, 'installment_no' => $i,
            'due_period' => sprintf('2026-%02d-01', 6 + $i),
            'scheduled_amount' => 1100, 'principal_portion' => 1000, 'interest_portion' => 100,
            'balance_after' => 1100 * (3 - $i), 'status' => LoanRepaymentStatus::Scheduled->value,
        ]);
    }

    // Model the prior disbursement: the loan principal already sits on Loans
    // Receivable (GL 1300) as a debit balance, so the settlement credit nets it
    // down. Seeded directly here because no disbursement JE is posted in-test.
    GlAccountBalance::query()
        ->whereIn('gl_account_id', GlAccount::where('code', '1300')->pluck('id'))
        ->update(['balance' => $opts['principal'] ?? 3000]);

    $settlement = FinalSettlement::create([
        'offboarding_case_id' => $case->id, 'status' => 'approved',
        'basic_salary' => 2000, 'years_of_service' => 3, 'accrued_leave_days' => 0,
        'working_days_per_month' => 22,
        'gratuity' => $opts['gross'] ?? 10000, 'severance' => 0, 'leave_encashment' => 0,
        'prorated_13th_month' => 0, 'ex_gratia' => 0, 'gross_settlement' => $opts['gross'] ?? 10000,
        'outstanding_loans' => $opts['outstanding'] ?? 3300, 'garnishments' => $opts['garn'] ?? 0,
        'other_deductions' => $opts['other'] ?? 0,
        'total_deductions' => ($opts['outstanding'] ?? 3300) + ($opts['garn'] ?? 0) + ($opts['other'] ?? 0) + ($opts['paye'] ?? 0),
        'paye_on_settlement' => $opts['paye'] ?? 0,
        'net_payable' => ($opts['gross'] ?? 10000) - (($opts['outstanding'] ?? 3300) + ($opts['garn'] ?? 0) + ($opts['other'] ?? 0) + ($opts['paye'] ?? 0)),
        'calculated_by' => User::factory()->create()->id, 'calculated_at' => now(),
        'breakdown' => [],
    ]);

    return [$settlement, $loan];
}

it('posts a balanced accrual that clears the loan (principal→1300, interest→4600)', function () {
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);

    $je = app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect($je)->not->toBeNull()
        ->and($je->source_type)->toBe(JournalSourceType::FinalSettlement) // JournalEntry casts source_type to the enum
        ->and($je->source_purpose)->toBe('accrual');

    // Loan fully cleared: 1300 nets to 0 for the 3000 principal; 4600 = 300 interest.
    expect(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01)   // principal removed
        ->and(settlementGl('4600'))->toEqualWithDelta(300.0, 0.01) // interest income
        ->and(settlementGl('2210'))->toEqualWithDelta(500.0, 0.01) // PAYE payable
        ->and(settlementGl('5130'))->toEqualWithDelta(10000.0, 0.01) // expense
        ->and(settlementGl('2300'))->toEqualWithDelta(6200.0, 0.01); // net = 10000-500-3300

    // Installments waived; loan paid off.
    expect(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(0)
        ->and($loan->fresh()->status)->toBe(LoanStatus::PaidOff);
});

it('is idempotent — re-posting returns the same JE and does not double-clear', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    $svc = app(SettlementPostingService::class);

    $first = $svc->postAccrual($settlement, User::factory()->create());
    $countAfterFirst = JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)->count();
    $svc->postAccrual($settlement->fresh(), User::factory()->create());

    expect(JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)->count())->toBe($countAfterFirst)
        ->and(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01); // not double-credited
});

it('shortfall: clears the loan only up to gross, leaving the rest scheduled and owed', function () {
    // gross 1500 < loan 3300 → only one 1100 installment fits (2200 would exceed 1500).
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 1500, 'outstanding' => 3300, 'paye' => 0]);

    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    // Only 1 installment cleared: principal 1000 → 1300 drops from 3000 to 2000; interest 100 → 4600.
    expect(settlementGl('1300'))->toEqualWithDelta(2000.0, 0.01)
        ->and(settlementGl('4600'))->toEqualWithDelta(100.0, 0.01)
        ->and(settlementGl('5130'))->toEqualWithDelta(1500.0, 0.01)
        ->and(settlementGl('2300'))->toEqualWithDelta(400.0, 0.01); // net = 1500 - 1100

    expect(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(2)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Repaying); // still owed
});

it('returns null and touches nothing when gross is zero', function () {
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 0]);

    $je = app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect($je)->toBeNull()
        ->and(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(3);
});

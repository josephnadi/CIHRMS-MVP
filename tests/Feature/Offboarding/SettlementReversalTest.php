<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Enums\SettlementStatus;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\LoanAccount;
use App\Models\LoanRepayment;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php'; // seedSettlementWithLoan + settlementGl

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

it('reverses an approved settlement: un-posts the accrual and restores the loan', function () {
    [$settlement, $loan] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    // After accrual: loan paid off, 1300 cleared to 0, expense recognised.
    expect(settlementGl('1300'))->toEqualWithDelta(0.0, 0.01)
        ->and($loan->fresh()->status)->toBe(LoanStatus::PaidOff);

    $reversed = app(OffboardingService::class)->reverseSettlement($settlement->fresh(), User::factory()->create(), 'Wrong figures');

    expect($reversed->status)->toBe(SettlementStatus::Cancelled)
        ->and($reversed->notes)->toContain('Wrong figures');

    // GL restored: accrual reversed → 1300 back to 3000, 5130 net 0.
    expect(settlementGl('1300'))->toEqualWithDelta(3000.0, 0.01)
        ->and(settlementGl('5130'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('4600'))->toEqualWithDelta(0.0, 0.01);

    // Loan restored: installments scheduled again, loan repaying, balance back.
    expect(LoanRepayment::where('loan_account_id', $loan->id)->where('status', LoanRepaymentStatus::Scheduled->value)->count())->toBe(3)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Repaying)
        ->and((float) $loan->fresh()->outstanding_balance)->toEqualWithDelta(3300.0, 0.01);
});

it('reverses a paid settlement: un-posts both payment and accrual', function () {
    OrgBankAccount::factory()->create([
        'purpose' => 'payroll', 'is_active' => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect(settlementGl('2300'))->toEqualWithDelta(0.0, 0.01)      // paid: liability cleared
        ->and(settlementGl('1110'))->toEqualWithDelta(-6200.0, 0.01);

    app(OffboardingService::class)->reverseSettlement($settlement->fresh(), User::factory()->create(), 'Paid in error');

    // Payment reversed → bank back to 0; accrual reversed → 2300 back to 0, 1300 back to 3000.
    expect(settlementGl('1110'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('2300'))->toEqualWithDelta(0.0, 0.01)
        ->and(settlementGl('1300'))->toEqualWithDelta(3000.0, 0.01);
});

it('refuses to reverse a settlement that is neither approved nor paid', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    $settlement->update(['status' => 'calculated']);

    expect(fn () => app(OffboardingService::class)->reverseSettlement($settlement->fresh(), User::factory()->create(), 'x'))
        ->toThrow(DomainException::class);
});

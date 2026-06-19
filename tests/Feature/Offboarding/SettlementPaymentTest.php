<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Enums\SettlementStatus;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php'; // reuse seedSettlementWithLoan + settlementGl

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

/** An active payroll bank mapped to GL 1110. */
function seedPayrollBank(): void
{
    OrgBankAccount::factory()->create([
        'purpose'       => 'payroll',
        'is_active'     => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
}

it('pays an approved settlement: clears 2300 and credits the payroll bank', function () {
    seedPayrollBank();
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]); // net = 10000-500-3300 = 6200
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    $paid = app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect($paid->status)->toBe(SettlementStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull()
        ->and(settlementGl('2300'))->toEqualWithDelta(0.0, 0.01)     // liability cleared
        ->and(settlementGl('1110'))->toEqualWithDelta(-6200.0, 0.01); // bank reduced by net

    expect(JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
        ->where('source_id', $settlement->id)->where('source_purpose', 'payment')->exists())->toBeTrue();
});

it('refuses to pay a settlement that is not approved', function () {
    seedPayrollBank();
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    $settlement->update(['status' => 'calculated']);

    expect(fn () => app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('marks paid with no payment JE when nothing is owed (net zero)', function () {
    seedPayrollBank();
    // gross 1100 fully absorbed by a single 1100 installment → net 0.
    [$settlement] = seedSettlementWithLoan(['gross' => 1100, 'paye' => 0, 'outstanding' => 3300]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    $paid = app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect($paid->status)->toBe(SettlementStatus::Paid)
        ->and(JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
            ->where('source_id', $settlement->id)->where('source_purpose', 'payment')->exists())->toBeFalse();
});

it('fails loud when no active payroll bank is configured', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]); // no seedPayrollBank()
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    expect(fn () => app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class);
});

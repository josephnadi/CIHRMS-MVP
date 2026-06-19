<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Offboarding\OffboardingService;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/SettlementAccrualTest.php';

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
    OrgBankAccount::factory()->create([
        'purpose' => 'payroll', 'is_active' => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
});

it('creates a pending settlement disbursement when a settlement is paid', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    $d = Disbursement::where('final_settlement_id', $settlement->id)->first();
    expect($d)->not->toBeNull()
        ->and($d->status)->toBe(DisbursementStatus::Pending)
        ->and((float) $d->gross_amount)->toEqualWithDelta(6200.0, 0.01);
});

it('does not create a disbursement when nothing was paid (net zero)', function () {
    // gross fully absorbed by the loan → net 0 → no payment JE → no disbursement.
    [$settlement] = seedSettlementWithLoan(['gross' => 1100, 'paye' => 0, 'outstanding' => 3300]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    app(OffboardingService::class)->paySettlement($settlement->fresh(), User::factory()->create());

    expect(Disbursement::where('final_settlement_id', $settlement->id)->exists())->toBeFalse();
});

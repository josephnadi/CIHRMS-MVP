<?php

declare(strict_types=1);

use App\Enums\SettlementStatus;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
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

// Mark a fresh 2FA challenge for the acting user so the `2fa:fresh` middleware
// on the pay route lets the request through (mirrors periodEndpoint2fa /
// ApPayment2faTest::apPay2fa).
function paySettlement2fa(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);

    return $user;
}

it('lets an authorized user pay an approved settlement', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000, 'paye' => 500]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $payer = User::factory()->create(['role' => 'super_admin']); // bypasses policy via before()
    $this->actingAs(paySettlement2fa($payer))
        ->post("/offboarding/{$case->id}/settlement/pay")
        ->assertRedirect();

    expect($settlement->fresh()->status)->toBe(SettlementStatus::Paid);
});

it('forbids a user without offboarding.approve from paying', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->post("/offboarding/{$case->id}/settlement/pay")
        ->assertForbidden();
});

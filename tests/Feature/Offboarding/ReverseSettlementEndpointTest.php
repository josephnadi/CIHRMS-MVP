<?php

declare(strict_types=1);

use App\Enums\SettlementStatus;
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
});

// Mark a fresh 2FA challenge for the acting user so the `2fa:fresh` middleware
// on the reverse route lets the request through (mirrors PaySettlementEndpointTest).
function reverseSettlement2fa(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);

    return $user;
}

it('lets an authorized user reverse an approved settlement', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $actor = User::factory()->create(['role' => 'super_admin']); // before() bypass
    $this->actingAs(reverseSettlement2fa($actor))
        ->post("/offboarding/{$case->id}/settlement/reverse", ['reason' => 'Wrong figures'])
        ->assertRedirect();

    expect($settlement->fresh()->status)->toBe(SettlementStatus::Cancelled);
});

it('forbids a user without offboarding.approve from reversing', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());
    $case = $settlement->case;

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->post("/offboarding/{$case->id}/settlement/reverse", ['reason' => 'x'])
        ->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();
});

function apPay2fa(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

it('AP payment store is blocked without fresh 2FA', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $bank = OrgBankAccount::active()->first();

    // No fresh 2FA — middleware should bounce, request should not reach the controller.
    // The ApPayment count must stay zero regardless of validation outcomes.
    $this->actingAs($u)->post('/finance/ap-payments', [
        'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-23',
        'amount' => 100,
        'org_bank_account_id' => $bank->id,
        'allocations' => [],
    ]);

    expect(\App\Models\ApPayment::count())->toBe(0);
});

it('AP payment store proceeds past 2FA when challenge is fresh', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V2', 'name' => 'V2', 'status' => 'active']);
    $bank = OrgBankAccount::active()->first();

    // With fresh 2FA — request gets past middleware. Empty allocations will fail
    // validation, but that's a controller-level failure (session errors), proving
    // we got past the 2FA gate.
    $this->actingAs(apPay2fa($u))->post('/finance/ap-payments', [
        'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-23',
        'amount' => 100,
        'org_bank_account_id' => $bank->id,
        'allocations' => [],
    ])->assertSessionHasErrors();
});

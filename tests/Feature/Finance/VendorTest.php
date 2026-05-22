<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VendorSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new VendorSeeder())->run();
});

it('lets finance_officer list vendors', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/vendors')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Vendors/Index'));
});

it('forbids employee', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/vendors')->assertForbidden();
});

it('auditor can view but not create', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($u)->get('/finance/vendors')->assertOk();
    $this->actingAs($u)->post('/finance/vendors', ['code' => 'X', 'name' => 'Y'])->assertForbidden();
});

it('finance_officer creates a vendor', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->post('/finance/vendors', [
        'code' => 'VEN-NEW', 'name' => 'New Co', 'status' => 'active',
    ])->assertRedirect();

    expect(Vendor::where('code', 'VEN-NEW')->exists())->toBeTrue();
});

it('rejects vendor with non-expense default_expense_gl', function () {
    $u  = User::factory()->create(['role' => 'finance_officer']);
    $ap = GlAccount::where('code', '2100')->firstOrFail();

    $this->actingAs($u)->post('/finance/vendors', [
        'code' => 'VEN-X', 'name' => 'X', 'status' => 'active',
        'default_expense_gl_account_id' => $ap->id,
    ])->assertSessionHasErrors(['default_expense_gl_account_id']);
});

it('rejects vendor with non-liability default_ap_gl', function () {
    $u  = User::factory()->create(['role' => 'finance_officer']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();

    $this->actingAs($u)->post('/finance/vendors', [
        'code' => 'VEN-Y', 'name' => 'Y', 'status' => 'active',
        'default_ap_gl_account_id' => $expense->id,
    ])->assertSessionHasErrors(['default_ap_gl_account_id']);
});

it('archive endpoint soft-deletes', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'VEN-ARC', 'name' => 'Arch', 'status' => 'active']);

    $this->actingAs($u)->delete("/finance/vendors/{$vendor->id}")->assertRedirect();
    expect(Vendor::find($vendor->id))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
});

it('finance_officer can list customers', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/customers')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Customers/Index'));
});

it('auditor can list (read-only) but cannot create', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($u)->get('/finance/customers')->assertOk();
    $this->actingAs($u)->post('/finance/customers', [
        'code' => 'CUS-X', 'name' => 'X',
    ])->assertForbidden();
});

it('employee gets 403 on the customers index', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/customers')->assertForbidden();
});

it('creates a customer via POST', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->post('/finance/customers', [
        'code'   => 'CUS-NEW',
        'name'   => 'Brand New Customer',
        'status' => 'active',
    ])->assertRedirect();

    expect(Customer::where('code', 'CUS-NEW')->exists())->toBeTrue();
});

it('rejects a default_income_gl_account_id that is not income type', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $arGl = GlAccount::where('code', '1200')->firstOrFail(); // asset, not income

    $this->actingAs($u)->post('/finance/customers', [
        'code' => 'CUS-BAD',
        'name' => 'Bad GL Customer',
        'default_income_gl_account_id' => $arGl->id,
    ])->assertSessionHasErrors(['default_income_gl_account_id']);
});

it('rejects a default_ar_gl_account_id that is not asset type', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $incomeGl = GlAccount::where('code', '4200')->firstOrFail(); // income, not asset

    $this->actingAs($u)->post('/finance/customers', [
        'code' => 'CUS-BAD2',
        'name' => 'Bad AR GL',
        'default_ar_gl_account_id' => $incomeGl->id,
    ])->assertSessionHasErrors(['default_ar_gl_account_id']);
});

it('updates a customer via PATCH', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $c = Customer::create(['code' => 'CUS-U', 'name' => 'Old', 'status' => 'active']);

    $this->actingAs($u)->patch("/finance/customers/{$c->id}", [
        'name' => 'New Name',
    ])->assertRedirect();

    expect($c->fresh()->name)->toBe('New Name');
});

it('archives a customer with no invoices', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $c = Customer::create(['code' => 'CUS-A', 'name' => 'A', 'status' => 'active']);

    $this->actingAs($u)->delete("/finance/customers/{$c->id}")->assertRedirect();
    expect($c->fresh()->trashed())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('lets finance_officer list chart of accounts', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance/accounts')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Accounts/Index'));
});

it('forbids employees from listing chart of accounts', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get('/finance/accounts')
        ->assertForbidden();
});

it('lets auditor list but not create accounts', function () {
    $auditor = User::factory()->create(['role' => 'auditor']);

    $this->actingAs($auditor)->get('/finance/accounts')->assertOk();
    $this->actingAs($auditor)->post('/finance/accounts', [
        'code' => '9999', 'name' => 'Hack', 'type' => 'expense',
    ])->assertForbidden();
});

it('lets finance_officer create a GL account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->post('/finance/accounts', [
            'code' => '6000',
            'name' => 'New Test Expense',
            'type' => 'expense',
        ])
        ->assertRedirect();

    expect(GlAccount::where('code', '6000')->exists())->toBeTrue();
});

it('rejects duplicate codes', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->post('/finance/accounts', ['code' => '1000', 'name' => 'Dup', 'type' => 'asset'])
        ->assertSessionHasErrors(['code']);
});

it('rejects self-referential parent on update', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    $this->actingAs($finance)
        ->patch("/finance/accounts/{$acc->id}", [
            'code' => $acc->code,
            'name' => $acc->name,
            'type' => $acc->type->value,
            'parent_id' => $acc->id,
        ])
        ->assertSessionHasErrors(['parent_id']);
});

it('lets finance_officer archive a GL account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $acc = GlAccount::where('code', '5500')->firstOrFail();

    $this->actingAs($finance)
        ->delete("/finance/accounts/{$acc->id}")
        ->assertRedirect();

    expect(GlAccount::find($acc->id))->toBeNull();
    expect(GlAccount::withTrashed()->find($acc->id)->trashed())->toBeTrue();
});

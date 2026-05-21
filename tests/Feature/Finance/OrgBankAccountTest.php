<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
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

it('lets finance_officer list org bank accounts', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance/bank-accounts')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/BankAccounts/Index'));
});

it('forbids employees from listing org bank accounts', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get('/finance/bank-accounts')
        ->assertForbidden();
});

it('rejects bank account linked to a non-asset GL account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $liability = GlAccount::where('code', '2100')->firstOrFail();

    $this->actingAs($finance)
        ->post('/finance/bank-accounts', [
            'gl_account_id'  => $liability->id,
            'bank_name'      => 'Test Bank',
            'account_name'   => 'CIHRM Test',
            'account_number' => '9999999999',
            'purpose'        => 'operating',
        ])
        ->assertSessionHasErrors(['gl_account_id']);
});

it('lets finance_officer create a valid bank account', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $asset = GlAccount::create(['code' => '1199', 'name' => 'Bank — Misc', 'type' => 'asset']);
    \App\Models\GlAccountBalance::create(['gl_account_id' => $asset->id, 'balance' => 0]);

    $this->actingAs($finance)
        ->post('/finance/bank-accounts', [
            'gl_account_id'  => $asset->id,
            'bank_name'      => 'Test Bank',
            'account_name'   => 'CIHRM Test',
            'account_number' => '9999999999',
            'purpose'        => 'reserve',
        ])
        ->assertRedirect();

    expect(OrgBankAccount::where('account_number', '9999999999')->exists())->toBeTrue();
});

it('manager users with bank_accounts.manage see full account number in response payload', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $response = $this->actingAs($finance)->get('/finance/bank-accounts');
    $response->assertInertia(function ($p) {
        $banks = $p->toArray()['props']['banks']['data'];
        expect(collect($banks)->pluck('account_number')->every(fn ($n) => strlen($n) > 4))->toBeTrue();
    });
});

it('viewer users without bank_accounts.manage see masked account number', function () {
    $auditor = User::factory()->create(['role' => 'auditor']);

    $response = $this->actingAs($auditor)->get('/finance/bank-accounts');
    $response->assertInertia(function ($p) {
        $banks = $p->toArray()['props']['banks']['data'];
        foreach ($banks as $b) {
            expect($b['account_number'])->toMatch('/^•{4,}\d{4}$/u');
        }
    });
});

it('enforces unique (bank_name, account_number) combination', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $existing = OrgBankAccount::first();

    $this->actingAs($finance)
        ->post('/finance/bank-accounts', [
            'gl_account_id'  => $existing->gl_account_id,
            'bank_name'      => $existing->bank_name,
            'account_number' => $existing->account_number,
            'account_name'   => 'Dup',
            'purpose'        => 'operating',
        ])
        ->assertSessionHasErrors(['account_number']);
});

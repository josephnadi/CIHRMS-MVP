<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('renders the hub for finance_officer with expected aggregate keys', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Finance/Hub')
            ->has('cashPosition')
            ->has('outstandingLoans')
            ->has('pendingApprovals')
            ->has('statutoryCompliance')
            ->has('bankAccounts')
            ->has('nextPayroll')
        );
});

it('forbids employees from accessing the hub', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->get('/finance')
        ->assertForbidden();
});

it('hub cash position equals the sum of active bank account opening balances', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    // Seed includes 3 banks at zero opening balance.
    $this->actingAs($finance)
        ->get('/finance')
        ->assertInertia(fn ($p) => $p->where('cashPosition', fn ($v) => (float) $v === 0.0));

    // Now bump one bank's opening_balance and re-test.
    $bank = \App\Models\OrgBankAccount::first();
    $bank->update(['opening_balance' => 100000.00]);

    // Hub uses 60s cache; flush.
    \Illuminate\Support\Facades\Cache::flush();

    $this->actingAs($finance)
        ->get('/finance')
        ->assertInertia(fn ($p) => $p->where('cashPosition', fn ($v) => (float) $v === 100000.0));
});

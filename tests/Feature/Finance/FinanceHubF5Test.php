<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\OrgBankAccountSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
});

it('hub returns reconciliationStats key', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Finance/Hub')
            ->has('reconciliationStats')
        );
});

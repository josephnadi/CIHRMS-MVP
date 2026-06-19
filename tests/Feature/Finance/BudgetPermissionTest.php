<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants finance.budget.manage to finance_officer, not employee', function () {
    expect(User::factory()->create(['role' => 'finance_officer'])->hasPermission('finance.budget.manage'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('finance.budget.manage'))->toBeFalse();
});

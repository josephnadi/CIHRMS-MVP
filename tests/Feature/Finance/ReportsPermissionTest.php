<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants finance.reports.view to finance_officer and auditor, not employee', function () {
    expect(User::factory()->create(['role' => 'finance_officer'])->hasPermission('finance.reports.view'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'auditor'])->hasPermission('finance.reports.view'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('finance.reports.view'))->toBeFalse();
});

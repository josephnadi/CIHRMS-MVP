<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants posting_rules.manage to finance_officer and super_admin only', function () {
    $fo = User::factory()->create(['role' => 'finance_officer']);
    $sa = User::factory()->create(['role' => 'super_admin']);
    $emp = User::factory()->create(['role' => 'employee']);

    expect($fo->hasPermission('finance.posting_rules.manage'))->toBeTrue()
        ->and($sa->hasPermission('finance.posting_rules.manage'))->toBeTrue()
        ->and($emp->hasPermission('finance.posting_rules.manage'))->toBeFalse();
});

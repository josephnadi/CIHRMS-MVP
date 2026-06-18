<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants view/close/reopen to finance_officer and reserves lock for super_admin', function () {
    $fo  = User::factory()->create(['role' => 'finance_officer']);
    $sa  = User::factory()->create(['role' => 'super_admin']);
    $emp = User::factory()->create(['role' => 'employee']);

    expect($fo->hasPermission('finance.period.view'))->toBeTrue()
        ->and($fo->hasPermission('finance.period.close'))->toBeTrue()
        ->and($fo->hasPermission('finance.period.reopen'))->toBeTrue()
        ->and($fo->hasPermission('finance.period.lock'))->toBeFalse()
        ->and($sa->hasPermission('finance.period.lock'))->toBeTrue()
        ->and($emp->hasPermission('finance.period.view'))->toBeFalse();
});

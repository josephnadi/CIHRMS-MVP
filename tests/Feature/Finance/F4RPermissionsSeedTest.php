<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('grants gateway.refund to finance_officer', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('gateway.refund');
});

it('auditor still does NOT have gateway.refund', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->not->toContain('gateway.refund');
});

it('legacy ROLE_PERMISSIONS lock-step for finance_officer', function () {
    expect(User::ROLE_PERMISSIONS['finance_officer'])->toContain('gateway.refund');
});

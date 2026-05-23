<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds the 3 new F4 permission slugs', function () {
    foreach (['gateway.view', 'gateway.create', 'gateway.refund'] as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing perm: {$slug}");
    }
});

it('grants gateway.view + gateway.create to finance_officer (F4-R later also grants refund)', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('gateway.view', 'gateway.create');
    // F4-R (2026-05-23) grants gateway.refund explicitly; covered by F4RPermissionsSeedTest.
});

it('grants gateway.view (view-only) to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('gateway.view');
    expect($slugs)->not->toContain('gateway.create', 'gateway.refund');
});

it('legacy ROLE_PERMISSIONS lock-step for finance_officer', function () {
    expect(User::ROLE_PERMISSIONS['finance_officer'])->toContain('gateway.view', 'gateway.create');
    // F4-R grants 'gateway.refund' too; F4RPermissionsSeedTest asserts the F4-R-specific behaviour.
});

it('super_admin gets gateway.refund via wildcard', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    expect($u->hasPermission('gateway.refund'))->toBeTrue();
});

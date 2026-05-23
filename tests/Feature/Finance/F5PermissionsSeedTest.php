<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds the 4 new F5 permission slugs', function () {
    foreach (['reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust'] as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing perm: {$slug}");
    }
});

it('grants all 4 F5 perms to finance_officer', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust');
});

it('grants only reconciliation.view to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('reconciliation.view');
    expect($slugs)->not->toContain('reconciliation.import', 'reconciliation.match', 'reconciliation.adjust');
});

it('legacy ROLE_PERMISSIONS lock-step for finance_officer', function () {
    expect(User::ROLE_PERMISSIONS['finance_officer'])->toContain('reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust');
});

it('super_admin gets all F5 perms via wildcard', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    expect($u->hasPermission('reconciliation.adjust'))->toBeTrue();
    expect($u->hasPermission('reconciliation.import'))->toBeTrue();
});

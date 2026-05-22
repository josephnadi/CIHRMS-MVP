<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds the new finance permission slugs', function () {
    foreach (['accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub'] as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing perm: {$slug}");
    }
});

it('grants new finance perms to finance_officer', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub');
});

it('grants read-only finance perms to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('accounts.view', 'bank_accounts.view');
    expect($slugs)->not->toContain('accounts.manage', 'bank_accounts.manage', 'finance.hub');
});

it('legacy fallback ROLE_PERMISSIONS stays in lock-step', function () {
    $finance = User::ROLE_PERMISSIONS['finance_officer'];
    $auditor = User::ROLE_PERMISSIONS['auditor'];

    foreach (['accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub'] as $slug) {
        expect($finance)->toContain($slug);
    }

    foreach (['accounts.view', 'bank_accounts.view'] as $slug) {
        expect($auditor)->toContain($slug);
    }
});

it('hasPermission resolves the new slugs for a finance officer', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);

    foreach (['accounts.view', 'accounts.manage', 'bank_accounts.view', 'bank_accounts.manage', 'finance.hub'] as $slug) {
        expect($u->hasPermission($slug))->toBeTrue("finance_officer should have {$slug}");
    }
});

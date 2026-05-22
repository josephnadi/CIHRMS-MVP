<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds the 8 new F2 permission slugs', function () {
    $f2 = [
        'vendors.view', 'vendors.manage',
        'ap_invoices.view', 'ap_invoices.create', 'ap_invoices.approve', 'ap_invoices.pay',
        'journal.view', 'journal.post_manual',
    ];
    foreach ($f2 as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing perm: {$slug}");
    }
});

it('grants 7 F2 perms to finance_officer (all except journal.post_manual)', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain(
        'vendors.view', 'vendors.manage',
        'ap_invoices.view', 'ap_invoices.create', 'ap_invoices.approve', 'ap_invoices.pay',
        'journal.view',
    );
    expect($slugs)->not->toContain('journal.post_manual');
});

it('grants 3 view-only F2 perms to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('vendors.view', 'ap_invoices.view', 'journal.view');
    expect($slugs)->not->toContain('vendors.manage', 'ap_invoices.create', 'ap_invoices.approve', 'ap_invoices.pay', 'journal.post_manual');
});

it('legacy ROLE_PERMISSIONS stays in lock-step for finance_officer', function () {
    foreach ([
        'vendors.view', 'vendors.manage',
        'ap_invoices.view', 'ap_invoices.create', 'ap_invoices.approve', 'ap_invoices.pay',
        'journal.view',
    ] as $slug) {
        expect(User::ROLE_PERMISSIONS['finance_officer'])->toContain($slug);
    }
});

it('hasPermission resolves the new slugs for a finance officer', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    foreach (['vendors.manage', 'ap_invoices.approve', 'ap_invoices.pay', 'journal.view'] as $slug) {
        expect($u->hasPermission($slug))->toBeTrue("missing for finance_officer: {$slug}");
    }
    expect($u->hasPermission('journal.post_manual'))->toBeFalse();
});

it('super_admin gets journal.post_manual via wildcard', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    expect($u->hasPermission('journal.post_manual'))->toBeTrue();
});

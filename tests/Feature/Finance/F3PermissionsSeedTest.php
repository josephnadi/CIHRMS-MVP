<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds all 8 F3 permission slugs', function () {
    $slugs = [
        'customers.view', 'customers.manage',
        'ar_invoices.view', 'ar_invoices.create', 'ar_invoices.approve',
        'ar_invoices.receive', 'ar_invoices.write_off',
        'statements.view',
    ];
    foreach ($slugs as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing slug: {$slug}");
    }
});

it('grants all 8 F3 slugs to finance_officer', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $granted = $role->permissions->pluck('slug')->all();
    foreach ([
        'customers.view', 'customers.manage',
        'ar_invoices.view', 'ar_invoices.create', 'ar_invoices.approve',
        'ar_invoices.receive', 'ar_invoices.write_off',
        'statements.view',
    ] as $slug) {
        expect($granted)->toContain($slug);
    }
});

it('grants only the 3 read-only F3 slugs to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $granted = $role->permissions->pluck('slug')->all();

    foreach (['customers.view', 'ar_invoices.view', 'statements.view'] as $slug) {
        expect($granted)->toContain($slug);
    }
    foreach (['customers.manage', 'ar_invoices.create', 'ar_invoices.approve', 'ar_invoices.receive', 'ar_invoices.write_off'] as $slug) {
        expect($granted)->not->toContain($slug);
    }
});

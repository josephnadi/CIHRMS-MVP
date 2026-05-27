<?php

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

it('hasPermission accepts both Permission enum and legacy string', function () {
    (new RolePermissionSeeder())->run();
    $u = User::factory()->create(['role' => 'hr_admin']);

    expect($u->hasPermission(Permission::EmployeesView))->toBeTrue();
    expect($u->hasPermission('employees.view'))->toBeTrue();
    expect($u->hasPermission(Permission::PayrollApprove))->toBeFalse();
});

it('every enum case has a unique slug', function () {
    $values = array_map(fn (Permission $p) => $p->value, Permission::cases());
    expect(array_unique($values))->toHaveCount(count($values));
});

it('every Permission enum case maps to a slug registered in some seeder catalog', function () {
    $main     = (new ReflectionClass(RolePermissionSeeder::class))->getConstant('PERMISSIONS');
    $incident = (new ReflectionClass(\Database\Seeders\IncidentPermissionsSeeder::class))->getConstant('PERMISSIONS');

    $catalogSlugs = array_merge(array_keys($main ?: []), array_keys($incident ?: []));

    foreach (Permission::cases() as $case) {
        expect($catalogSlugs)->toContain($case->value);
    }
});

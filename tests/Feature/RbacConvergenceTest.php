<?php

declare(strict_types=1);

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

function seederCatalogKeys(): array
{
    return array_keys((new ReflectionClass(RolePermissionSeeder::class))->getConstant('PERMISSIONS'));
}

it('every performance/learning route permission exists in both the enum and the DB catalog', function () {
    // These are the permission strings the performance + learning route groups
    // gate on (routes/web.php). Each must be a real enum case AND a seeded
    // catalog entry, or the roles UI can never grant it.
    $required = [
        'performance.view', 'performance.manage', 'performance.calibrate',
        'performance.calibrate_apply', 'performance.pip_manage',
        'learning.view', 'learning.manage', 'learning.compliance.manage',
    ];

    $enumValues = array_map(fn ($c) => $c->value, PermissionEnum::cases());
    $catalog    = seederCatalogKeys();

    foreach ($required as $perm) {
        expect($enumValues)->toContain($perm);
        expect($catalog)->toContain($perm);
    }
});

it('grants performance + learning view/manage to HR and line-management roles in the DB', function () {
    foreach (['hr_admin', 'manager', 'dept_head'] as $slug) {
        $perms = Role::where('slug', $slug)->firstOrFail()->permissions->pluck('slug');
        expect($perms)->toContain('performance.view', 'performance.manage', 'learning.view', 'learning.manage');
    }
});

it('grants employees performance.view + learning.view but not the manage rights', function () {
    $perms = Role::where('slug', 'employee')->firstOrFail()->permissions->pluck('slug');

    expect($perms)->toContain('performance.view', 'learning.view')
        ->and($perms)->not->toContain('performance.manage')
        ->and($perms)->not->toContain('learning.manage');
});

it('grants learning.view to the read-mostly roles (finance_officer, it_support, auditor)', function () {
    foreach (['finance_officer', 'it_support', 'auditor'] as $slug) {
        $perms = Role::where('slug', $slug)->firstOrFail()->permissions->pluck('slug');
        expect($perms)->toContain('learning.view');
    }
});

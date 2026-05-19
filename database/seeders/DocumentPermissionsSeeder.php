<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds the three documents-module permissions and attaches them to roles.
 *
 * The plan's reference implementation targets Spatie's permission package
 * (Permission::firstOrCreate(['name' => ...]) + Role::givePermissionTo()),
 * but this codebase ships its own RBAC (\App\Models\Permission +
 * \App\Models\Role with a custom `role_permissions` pivot and slug-based
 * keys — see RolePermissionSeeder). We honour the local convention here.
 *
 * Idempotent: safe to re-run.
 */
class DocumentPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'documents.view'   => ['Documents', 'View documents directory and document detail'],
        'documents.create' => ['Documents', 'Create documents and upload new versions'],
        'documents.manage' => ['Documents', 'Administer any document (org-wide override)'],
    ];

    /**
     * Roles that get the documents.manage override.
     * Anyone else gets view + create only.
     */
    private const MANAGE_ROLE_SLUGS = ['super_admin', 'hr_admin'];

    public function run(): void
    {
        // 1. Permissions (idempotent on the `slug` natural key).
        foreach (self::PERMISSIONS as $slug => [$group, $description]) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'        => str_replace('.', ': ', $slug),
                    'group'       => $group,
                    'description' => $description,
                ]
            );
        }

        // Cache the resolved permission IDs once.
        $allPermIds = Permission::whereIn('slug', array_keys(self::PERMISSIONS))
            ->pluck('id', 'slug');

        $baseSlugs   = ['documents.view', 'documents.create'];
        $manageSlugs = ['documents.view', 'documents.create', 'documents.manage'];

        // 2. Attach to every existing role. syncWithoutDetaching preserves
        //    permissions assigned by other seeders (the canonical role catalog
        //    lives in RolePermissionSeeder; we only add documents.* here).
        foreach (Role::all() as $role) {
            $slugs = in_array($role->slug, self::MANAGE_ROLE_SLUGS, true)
                ? $manageSlugs
                : $baseSlugs;

            $ids = $allPermIds->only($slugs)->values()->all();
            $role->permissions()->syncWithoutDetaching($ids);
        }

        // 3. Flush the user permission cache so the new perms surface
        //    immediately on the next request (mirrors RolePermissionSeeder).
        Cache::flush();
    }
}

<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class IncidentPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'incidents.review' => [
            'Incidents',
            'Can be assigned to and view confidential incident reports submitted by employees',
        ],
    ];

    public function run(): void
    {
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

        // No role gets incidents.review by default. Super-admin / HR-admin
        // grants it explicitly via the RBAC UI to the CEO + chosen execs.
        Cache::flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('super_admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('assets.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        if ($user->hasPermission('assets.view')) {
            return true;
        }
        $assignedEmployeeId = $asset->currentAssignment?->employee_id;
        return $assignedEmployeeId !== null && $assignedEmployeeId === $user->employee?->id;
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('assets.manage');
    }

    public function assign(User $user, Asset $asset): bool
    {
        if ($user->hasPermission('assets.manage')) {
            return true;
        }
        return $user->hasPermission('assets.assign');
    }
}

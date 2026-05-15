<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;

class PositionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('positions.view') || $user->hasPermission('positions.manage');
    }

    public function view(User $user, Position $position): bool
    {
        if ($user->hasPermission('positions.view')) return true;
        return $position->department_id !== null && $user->managesDepartment($position->department_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('positions.manage');
    }

    public function update(User $user, Position $position): bool
    {
        return $user->hasPermission('positions.manage');
    }

    public function freeze(User $user, Position $position): bool
    {
        return $user->hasPermission('positions.manage');
    }

    public function assign(User $user, Position $position): bool
    {
        return $user->hasPermission('positions.manage');
    }
}

<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employees.view') || $user->hasPermission('employees.manage');
    }

    public function view(User $user, Department $department): bool
    {
        if ($user->hasPermission('employees.view') || $user->hasPermission('employees.manage')) return true;
        return $user->managesDepartment($department->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.manage');
    }

    public function update(User $user, Department $department): bool
    {
        if ($user->hasPermission('employees.manage')) return true;
        return $user->managesDepartment($department->id);
    }

    /**
     * Deleting a department mutates the org chart — even a dept-head should
     * not be able to remove their own team. Reserved for HR / super_admin.
     */
    public function delete(User $user, Department $department): bool
    {
        return $user->hasPermission('employees.manage');
    }
}

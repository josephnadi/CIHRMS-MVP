<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    /** Wildcards — super admins always pass. */
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employees.view')
            || $user->hasPermission('employees.manage')
            || $user->managedDepartmentIds()->isNotEmpty();
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->hasPermission('employees.view') || $user->hasPermission('employees.manage')) return true;
        if ($user->managesDepartment($employee->department_id))                                  return true;
        if ($user->id === $employee->user_id)                                                    return true;
        // Direct manager?
        if ($user->employee?->id && $employee->manager_id === $user->employee->id)               return true;

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        if ($user->hasPermission('employees.manage'))               return true;
        if ($user->managesDepartment($employee->department_id))     return true;
        // Self-edit (limited fields enforced in the FormRequest).
        return $user->id === $employee->user_id;
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employees.manage');
    }

    public function viewSalary(User $user, Employee $employee): bool
    {
        if ($user->hasPermission('employees.view_salary'))  return true;
        return $user->id === $employee->user_id;
    }

    public function transfer(User $user, Employee $employee): bool
    {
        if ($user->hasPermission('employees.manage'))               return true;
        return $user->hasPermission('employees.transfer')
            && $user->managesDepartment($employee->department_id);
    }
}

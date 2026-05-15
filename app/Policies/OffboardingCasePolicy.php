<?php

namespace App\Policies;

use App\Models\OffboardingCase;
use App\Models\User;

class OffboardingCasePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('offboarding.view') || $user->hasPermission('offboarding.manage');
    }

    public function view(User $user, OffboardingCase $case): bool
    {
        if ($user->hasPermission('offboarding.view') || $user->hasPermission('offboarding.manage')) return true;

        // Self-view: an employee can see their own off-boarding case
        if ($case->employee?->user_id === $user->id) return true;

        // Dept-scoped heads see cases for their department's employees
        return $case->employee?->department_id !== null
            && $user->managesDepartment($case->employee->department_id);
    }

    public function initiate(User $user): bool
    {
        return $user->hasPermission('offboarding.initiate');
    }

    public function clear(User $user): bool
    {
        // Multiple departments sign off clearance items; broad gate.
        return $user->hasPermission('offboarding.clear')
            || $user->hasPermission('offboarding.manage');
    }

    public function calculateSettlement(User $user): bool
    {
        return $user->hasPermission('offboarding.settle');
    }

    public function approveSettlement(User $user): bool
    {
        return $user->hasPermission('offboarding.approve');
    }

    public function complete(User $user, OffboardingCase $case): bool
    {
        return $user->hasPermission('offboarding.manage');
    }
}

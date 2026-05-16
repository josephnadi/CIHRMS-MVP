<?php

namespace App\Policies;

use App\Models\PerformanceContract;
use App\Models\User;

class PerformanceContractPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.view') || $user->hasPermission('performance.manage');
    }

    public function view(User $user, PerformanceContract $contract): bool
    {
        if ($user->hasPermission('performance.manage')) return true;

        // Self-view
        if ($contract->employee?->user_id === $user->id) return true;

        // Supervisor view
        if ($contract->supervisor?->user_id === $user->id) return true;

        // Dept-scoped manager
        return $contract->employee?->department_id !== null
            && $user->managesDepartment($contract->employee->department_id);
    }

    public function draft(User $user): bool
    {
        return $user->hasPermission('performance.manage');
    }

    public function sign(User $user, PerformanceContract $contract): bool
    {
        return $contract->employee?->user_id === $user->id
            || $contract->supervisor?->user_id === $user->id;
    }

    public function evaluate(User $user, PerformanceContract $contract): bool
    {
        return $user->hasPermission('performance.manage');
    }
}

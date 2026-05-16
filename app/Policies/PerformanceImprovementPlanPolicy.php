<?php

namespace App\Policies;

use App\Models\PerformanceImprovementPlan;
use App\Models\User;

class PerformanceImprovementPlanPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.pip_manage') || $user->hasPermission('performance.manage');
    }

    public function view(User $user, PerformanceImprovementPlan $pip): bool
    {
        if ($user->hasPermission('performance.pip_manage') || $user->hasPermission('performance.manage')) return true;
        // Subject employee may see their own PIP
        return $pip->employee?->user_id === $user->id;
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('performance.pip_manage');
    }
}

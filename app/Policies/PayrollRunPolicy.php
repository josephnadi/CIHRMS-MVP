<?php

namespace App\Policies;

use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\User;

class PayrollRunPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view_all')
            || $user->hasPermission('payroll.run')
            || $user->hasPermission('payroll.approve');
    }

    public function view(User $user, PayrollRun $run): bool
    {
        if ($user->hasPermission('payroll.view_all') || $user->hasPermission('payroll.run')) return true;
        // Department-scoped heads see runs targeting their dept(s).
        return $run->department_id !== null && $user->managesDepartment($run->department_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.run');
    }

    public function approve(User $user, PayrollRun $run): bool
    {
        // Dual control: creator may not approve their own run.
        if ($run->created_by === $user->id) return false;
        return $user->hasPermission('payroll.approve')
            && $run->status === PayrollRunStatus::Calculated;
    }

    public function reverse(User $user, PayrollRun $run): bool
    {
        return $user->hasPermission('payroll.reverse')
            && in_array($run->status, [PayrollRunStatus::Approved, PayrollRunStatus::Paid], true);
    }
}

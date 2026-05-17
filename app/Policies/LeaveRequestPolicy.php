<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.request');
    }

    public function view(User $user, LeaveRequest $leave): bool
    {
        if ($user->hasPermission('leave.manage') || $user->hasPermission('leave.approve')) {
            // HR/managers see all (further dept-scoping below for managers).
            if ($user->hasPermission('leave.manage')) return true;

            // Approver: must manage the dept the requesting employee belongs to.
            return $user->managesDepartment($leave->employee?->department_id);
        }

        return $leave->employee?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leave.request');
    }

    public function approve(User $user, LeaveRequest $leave): bool
    {
        if ($user->hasPermission('leave.manage')) return true;

        return $user->hasPermission('leave.approve')
            && $user->managesDepartment($leave->employee?->department_id);
    }

    /**
     * Cancel-own — the requester can withdraw their own leave while it is
     * still Pending. HR with leave.manage can cancel anyone's. Once a request
     * is Approved or Rejected the workflow is closed; if you really need to
     * undo that, do it through approval-reversal, not this path.
     */
    public function cancel(User $user, LeaveRequest $leave): bool
    {
        if ($leave->status?->value !== 'pending') return false;
        if ($user->hasPermission('leave.manage')) return true;
        return $leave->employee?->user_id === $user->id;
    }
}

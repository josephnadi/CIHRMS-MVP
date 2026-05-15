<?php

namespace App\Policies;

use App\Models\LoanAccount;
use App\Models\User;

class LoanAccountPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('loans.view') || $user->hasPermission('loans.manage');
    }

    public function view(User $user, LoanAccount $loan): bool
    {
        if ($user->hasPermission('loans.view') || $user->hasPermission('loans.manage')) return true;
        return $loan->employee?->user_id === $user->id;
    }

    public function apply(User $user): bool
    {
        return $user->hasPermission('loans.apply');
    }

    public function approve(User $user, LoanAccount $loan): bool
    {
        // Dual control: applicant cannot also approve.
        if ($loan->applied_by === $user->id) return false;
        return $user->hasPermission('loans.approve');
    }

    public function disburse(User $user, LoanAccount $loan): bool
    {
        return $user->hasPermission('loans.disburse');
    }
}

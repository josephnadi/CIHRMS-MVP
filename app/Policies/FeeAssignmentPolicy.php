<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FeeAssignment;
use App\Models\User;

/**
 * Gates the assignments grid + billing-run actions. Read is finance-OR-
 * member-management; write (running a billing, cancelling an assignment)
 * is restricted to `billing.*` permissions held by finance officers and
 * super admins.
 */
class FeeAssignmentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('billing.run')
            || $user->hasPermission('billing.cancel')
            || $user->hasPermission('fee_catalog.view')
            || $user->hasPermission('members.view');
    }

    public function view(User $user, FeeAssignment $assignment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('billing.run');
    }

    public function cancel(User $user, FeeAssignment $assignment): bool
    {
        return $user->hasPermission('billing.cancel');
    }
}

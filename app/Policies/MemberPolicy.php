<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Member;
use App\Models\User;

/**
 * Gates admin access to the Member directory. Members themselves never
 * hit this policy — the portal guard (M2) sees only their own row.
 *
 * `members.view`    — list / view member records
 * `members.manage`  — create / update / delete + bulk import
 */
class MemberPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('members.view') || $user->hasPermission('members.manage');
    }

    public function view(User $user, Member $member): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('members.manage');
    }

    public function update(User $user, Member $member): bool
    {
        return $user->hasPermission('members.manage');
    }

    public function delete(User $user, Member $member): bool
    {
        return $user->hasPermission('members.manage');
    }
}

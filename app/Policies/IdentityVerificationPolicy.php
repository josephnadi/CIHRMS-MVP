<?php

namespace App\Policies;

use App\Models\IdentityVerification;
use App\Models\User;

class IdentityVerificationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('identity.view');
    }

    public function view(User $user, IdentityVerification $row): bool
    {
        if ($user->hasPermission('identity.view')) return true;
        return $row->employee?->user_id === $user->id;
    }

    public function verify(User $user): bool
    {
        return $user->hasPermission('identity.verify');
    }
}

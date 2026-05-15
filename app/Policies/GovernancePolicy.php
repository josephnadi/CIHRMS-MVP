<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Policy;
use App\Models\User;

class GovernancePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('super_admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('governance.view');
    }

    public function view(User $user, Policy $policy): bool
    {
        return $user->hasPermission('governance.view');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('governance.manage');
    }

    public function acknowledge(User $user): bool
    {
        return $user->hasPermission('governance.acknowledge');
    }

    public function manageCertifications(User $user): bool
    {
        return $user->hasPermission('governance.cert_manage');
    }
}

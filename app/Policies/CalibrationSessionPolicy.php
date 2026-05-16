<?php

namespace App\Policies;

use App\Models\CalibrationSession;
use App\Models\User;

class CalibrationSessionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.calibrate')
            || $user->hasPermission('performance.calibrate_apply')
            || $user->hasPermission('performance.manage');
    }

    public function view(User $user, CalibrationSession $session): bool
    {
        return $this->viewAny($user);
    }

    public function facilitate(User $user): bool
    {
        return $user->hasPermission('performance.calibrate');
    }

    public function apply(User $user, CalibrationSession $session): bool
    {
        // Dual control enforced inside the service; this gate is the permission half.
        return $user->hasPermission('performance.calibrate_apply');
    }
}

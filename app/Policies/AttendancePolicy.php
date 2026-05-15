<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

class AttendancePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view') || $user->hasPermission('attendance.manage');
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        if ($user->hasPermission('attendance.view')) return true;
        // Self-service: an employee can see their own punches
        return $record->employee?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('attendance.manage');
    }

    public function manageShifts(User $user): bool
    {
        return $user->hasPermission('attendance.shift_manage');
    }
}

<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view')
            || $user->hasPermission('payroll.manage');
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->hasPermission('payroll.view') || $user->hasPermission('payroll.manage')) return true;
        return $payment->employee?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.manage');
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payroll.manage');
    }

    public function markPaid(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payroll.manage');
    }
}

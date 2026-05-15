<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BenefitClaim;
use App\Models\BenefitEnrolment;
use App\Models\BenefitPlan;
use App\Models\User;

class BenefitsPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('super_admin') ? true : null;
    }

    public function viewPlan(User $user, BenefitPlan $plan): bool
    {
        return $user->hasPermission('benefits.view');
    }

    public function viewAnyPlan(User $user): bool
    {
        return $user->hasPermission('benefits.view');
    }

    public function managePlans(User $user): bool
    {
        return $user->hasPermission('benefits.manage');
    }

    public function viewEnrolment(User $user, BenefitEnrolment $enrolment): bool
    {
        if ($user->hasPermission('benefits.view_all')) return true;
        return $enrolment->employee_id === $user->employee?->id;
    }

    public function enrol(User $user): bool
    {
        return $user->hasPermission('benefits.enrol');
    }

    public function viewClaim(User $user, BenefitClaim $claim): bool
    {
        if ($user->hasPermission('benefits.view_all')) return true;
        return $claim->enrolment?->employee_id === $user->employee?->id;
    }

    public function submitClaim(User $user, BenefitEnrolment $enrolment): bool
    {
        return $user->hasPermission('benefits.claim') && $enrolment->employee_id === $user->employee?->id;
    }

    public function manageClaims(User $user): bool
    {
        return $user->hasPermission('benefits.manage');
    }
}

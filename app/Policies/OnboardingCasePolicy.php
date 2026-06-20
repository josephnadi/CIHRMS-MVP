<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OnboardingCase;
use App\Models\User;

class OnboardingCasePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('onboarding.view') || $user->hasPermission('onboarding.manage');
    }

    public function view(User $user, OnboardingCase $case): bool
    {
        if ($user->hasPermission('onboarding.view') || $user->hasPermission('onboarding.manage')) {
            return true;
        }

        return $case->employee?->user_id === $user->id; // self-view
    }

    public function initiate(User $user): bool
    {
        return $user->hasPermission('onboarding.initiate');
    }

    public function complete(User $user): bool
    {
        return $user->hasPermission('onboarding.complete');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('onboarding.manage');
    }
}

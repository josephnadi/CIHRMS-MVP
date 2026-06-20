<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Facades\Log;

class InitiateOnboardingOnHire
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function handle(EmployeeCreated $event): void
    {
        $employee = $event->employee;
        $actor    = $event->actor; // the user who created the employee

        // No hire date → not a real hire yet; no actor → no initiator to attribute.
        if (! $employee->hire_date || $actor === null) {
            return;
        }

        try {
            // initiate is idempotent (returns the existing open case).
            $this->onboarding->initiate($employee, $actor);
        } catch (\Throwable $e) {
            // Onboarding must never block employee creation.
            Log::warning('Auto onboarding initiation failed: '.$e->getMessage(), ['employee_id' => $employee->id]);
        }
    }
}

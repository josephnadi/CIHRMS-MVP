<?php

declare(strict_types=1);

use App\Enums\OnboardingArea;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Models\User;

it('stores a case with tasks, casts enums, and computes completeness', function () {
    $employee = Employee::factory()->create();
    $case = OnboardingCase::create([
        'reference' => 'ON-2026-00001', 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'status' => 'in_progress',
        'hire_date' => '2026-06-01',
    ]);

    $req = OnboardingTask::create(['onboarding_case_id' => $case->id, 'area' => 'it_provisioning',
        'label' => 'Issue laptop', 'status' => 'pending', 'is_required' => true]);
    OnboardingTask::create(['onboarding_case_id' => $case->id, 'area' => 'mentorship',
        'label' => 'Assign mentor', 'status' => 'pending', 'is_required' => false]);

    expect($case->fresh()->status)->toBe(OnboardingStatus::InProgress)
        ->and($case->tasks()->count())->toBe(2)
        ->and($req->fresh()->area)->toBe(OnboardingArea::ItProvisioning)
        ->and($case->isComplete())->toBeFalse(); // required task still pending

    $req->update(['status' => OnboardingTaskStatus::Completed->value]);
    expect($case->fresh()->isComplete())->toBeTrue()           // only the optional one remains pending
        ->and($case->fresh()->progress())->toBeGreaterThan(0.0);
});

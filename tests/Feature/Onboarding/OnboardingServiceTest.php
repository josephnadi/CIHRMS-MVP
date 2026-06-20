<?php

declare(strict_types=1);

use App\Enums\CourseCategory;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;

beforeEach(fn () => $this->svc = app(OnboardingService::class));

it('initiates a case: seeds the template, sets in_progress, auto-enrols onboarding courses', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $course = Course::create(['title' => 'Welcome', 'category' => CourseCategory::Onboarding->value,
        'is_published' => true, 'created_by' => User::factory()->create()->id]);

    $case = $this->svc->initiate($employee, User::factory()->create());

    expect($case->status)->toBe(OnboardingStatus::InProgress)
        ->and($case->reference)->toStartWith('ON-')
        ->and($case->tasks()->count())->toBeGreaterThan(4)
        ->and(Enrolment::where('course_id', $course->id)->where('employee_id', $employee->id)->exists())->toBeTrue();

    // idempotent — second initiate returns the same open case
    expect($this->svc->initiate($employee->fresh(), User::factory()->create())->id)->toBe($case->id);
});

it('completes and skips tasks, and blocks case completion until required tasks are done', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $by = User::factory()->create();
    $case = $this->svc->initiate($employee, $by);

    // cannot complete while required tasks pending
    expect(fn () => $this->svc->complete($case->fresh(), $by))->toThrow(DomainException::class);

    // complete all required, skip the optional ones
    foreach ($case->tasks()->where('is_required', true)->get() as $t) {
        $this->svc->completeTask($t, $by, 'done');
    }
    foreach ($case->tasks()->where('is_required', false)->get() as $t) {
        $this->svc->skipTask($t, $by, 'n/a');
    }

    $completed = $this->svc->complete($case->fresh(), $by);
    expect($completed->status)->toBe(OnboardingStatus::Completed)
        ->and($completed->completed_by)->toBe($by->id);
});

it('cannot complete an already-completed task', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $by = User::factory()->create();
    $case = $this->svc->initiate($employee, $by);
    $task = $case->tasks()->first();

    $this->svc->completeTask($task, $by);
    expect(fn () => $this->svc->completeTask($task->fresh(), $by))->toThrow(DomainException::class);
});

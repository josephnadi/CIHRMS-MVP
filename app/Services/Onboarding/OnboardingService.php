<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\CourseCategory;
use App\Enums\OnboardingArea;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Services\Finance\SequenceService;
use App\Services\LearningService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * New-hire onboarding lifecycle. Mirrors OffboardingService: a case is opened
 * (manually or on hire), a templated task checklist is seeded, the hire is
 * auto-enrolled in onboarding courses, task owners sign off, and HR completes
 * the case once every required task is done.
 */
class OnboardingService
{
    /** [area, label, required] — seeded on case creation. */
    public const DEFAULT_ONBOARDING_TEMPLATE = [
        [OnboardingArea::ItProvisioning,        'Issue laptop, phone & access badge',                 true],
        [OnboardingArea::ItProvisioning,        'Create email & system accounts',                     true],
        [OnboardingArea::HrOrientation,         'HR orientation & staff handbook walkthrough',        true],
        [OnboardingArea::HrOrientation,         'Collect statutory documents (Ghana Card, SSNIT, TIN)', true],
        [OnboardingArea::PolicyAcknowledgement, 'Acknowledge code of conduct & key policies',         true],
        [OnboardingArea::Learning,              'Complete mandatory onboarding courses',              true],
        [OnboardingArea::Mentorship,            'Assign onboarding buddy / mentor',                   false],
        [OnboardingArea::DeptIntroduction,      'Department introduction & first-week plan',          true],
    ];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly LearningService $learning,
    ) {
    }

    public function initiate(Employee $employee, User $by, ?string $hireDate = null, ?string $targetDate = null): OnboardingCase
    {
        if ($existing = $this->openCaseFor($employee)) {
            return $existing; // one open case per employee
        }

        return DB::transaction(function () use ($employee, $by, $hireDate, $targetDate) {
            $case = OnboardingCase::create([
                'reference'              => $this->nextReference(),
                'employee_id'            => $employee->id,
                'initiated_by'           => $by->id,
                'status'                 => OnboardingStatus::InProgress->value,
                'hire_date'              => $hireDate ?? $employee->hire_date,
                'target_completion_date' => $targetDate,
            ]);

            $this->seedDefaultTasks($case);
            $this->autoEnrolOnboardingCourses($employee);

            return $case->fresh('tasks');
        });
    }

    public function completeTask(OnboardingTask $task, User $by, ?string $notes = null): OnboardingTask
    {
        if ($task->status !== OnboardingTaskStatus::Pending) {
            throw new DomainException("Task '{$task->label}' is not pending (current: {$task->status->value}).");
        }

        $task->update([
            'status'       => OnboardingTaskStatus::Completed->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
            'notes'        => $notes,
        ]);

        return $task->fresh();
    }

    public function skipTask(OnboardingTask $task, User $by, string $reason): OnboardingTask
    {
        if ($task->status !== OnboardingTaskStatus::Pending) {
            throw new DomainException("Task '{$task->label}' is not pending.");
        }

        $task->update([
            'status'       => OnboardingTaskStatus::Skipped->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
            'notes'        => $reason,
        ]);

        return $task->fresh();
    }

    public function complete(OnboardingCase $case, User $by): OnboardingCase
    {
        if ($case->status === OnboardingStatus::Completed) {
            return $case;
        }
        if (! $case->isComplete()) {
            throw new DomainException('Cannot complete: required onboarding tasks are still pending.');
        }

        $case->update([
            'status'       => OnboardingStatus::Completed->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
        ]);

        return $case->fresh();
    }

    public function cancel(OnboardingCase $case, User $by, string $reason): OnboardingCase
    {
        if ($case->status === OnboardingStatus::Completed) {
            throw new DomainException('Cannot cancel a completed onboarding case.');
        }

        $case->update([
            'status'       => OnboardingStatus::Cancelled->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
        ]);

        return $case->fresh();
    }

    public function openCaseFor(Employee $employee): ?OnboardingCase
    {
        return OnboardingCase::where('employee_id', $employee->id)->open()->first();
    }

    private function seedDefaultTasks(OnboardingCase $case): void
    {
        foreach (self::DEFAULT_ONBOARDING_TEMPLATE as [$area, $label, $required]) {
            OnboardingTask::create([
                'onboarding_case_id' => $case->id,
                'area'               => $area->value,
                'label'              => $label,
                'status'             => OnboardingTaskStatus::Pending->value,
                'is_required'        => $required,
            ]);
        }
    }

    /** Best-effort: enrol the hire in every published onboarding course. */
    private function autoEnrolOnboardingCourses(Employee $employee): void
    {
        Course::query()->published()->category(CourseCategory::Onboarding)->get()
            ->each(fn (Course $course) => $this->learning->enrol($course, $employee));
    }

    private function nextReference(): string
    {
        $year = now()->year;

        return sprintf('ON-%04d-%05d', $year, $this->sequences->next("onboarding:{$year}"));
    }
}

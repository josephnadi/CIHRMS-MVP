<?php

namespace App\Listeners;

use App\Events\CourseCompleted;
use App\Services\LearningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * When a course is completed, copy each of its `skill_tags` into the
 * employee's EmployeeSkill record (idempotent — existing skills are kept).
 *
 * Auto-discovered via the typed CourseCompleted parameter on handle().
 */
class GrantSkillsOnCourseCompletion implements ShouldQueue
{
    public string $queue = 'analytics';

    public function __construct(protected LearningService $learning) {}

    public function handle(CourseCompleted $event): void
    {
        try {
            $added = $this->learning->grantSkillsFromCourse($event->enrolment);
            if ($added > 0) {
                Log::info("[learning] Granted {$added} new skill(s) to employee #{$event->enrolment->employee_id} from course #{$event->enrolment->course_id}");
            }
        } catch (\Throwable $e) {
            Log::warning('[learning] auto-skill grant failed', [
                'enrolment_id' => $event->enrolment->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\ComplianceRequirement;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Services\LearningService;

/**
 * Auto-assigns mandatory (compliance) courses to the employees a requirement
 * targets, stamping a due date. Idempotent and non-blocking: re-syncing never
 * duplicates or moves a due date, and a failure for one employee/requirement
 * does not abort the batch.
 */
class ComplianceAssignmentService
{
    public function __construct(private readonly LearningService $learning)
    {
    }

    /** Enrol the employee in the requirement's course and stamp requirement_id + due_at once. */
    public function assign(ComplianceRequirement $requirement, Employee $employee): ?Enrolment
    {
        $course = $requirement->course;
        if ($course === null) {
            return null;
        }

        $enrolment = $this->learning->enrol($course, $employee);

        if ($enrolment->requirement_id === null) {
            $enrolment->update([
                'requirement_id' => $requirement->id,
                'due_at'         => now()->addDays((int) $requirement->due_in_days),
            ]);
        }

        return $enrolment->fresh();
    }

    /** Assign every active employee the requirement targets. Returns rows assigned. */
    public function syncRequirement(ComplianceRequirement $requirement): int
    {
        if (! $requirement->is_active) {
            return 0;
        }

        $count = 0;
        $requirement->matchingEmployees()->each(function (Employee $employee) use ($requirement, &$count) {
            try {
                if ($this->assign($requirement, $employee)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                report($e); // never abort the batch
            }
        });

        return $count;
    }

    /** Sync every active requirement. Returns total rows assigned. */
    public function syncAll(): int
    {
        $total = 0;
        ComplianceRequirement::where('is_active', true)->each(function (ComplianceRequirement $r) use (&$total) {
            $total += $this->syncRequirement($r);
        });

        return $total;
    }

    /** Assign all active requirements that target a single employee (new-hire hook). Returns count. */
    public function assignForEmployee(Employee $employee): int
    {
        $count = 0;
        ComplianceRequirement::where('is_active', true)->get()->each(function (ComplianceRequirement $r) use ($employee, &$count) {
            try {
                if ($r->matches($employee) && $this->assign($r, $employee)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $count;
    }
}

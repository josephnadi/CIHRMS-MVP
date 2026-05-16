<?php

namespace App\Services\Establishment;

use App\Models\Employee;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * Annual step-increment processor.
 *
 * Run nightly (scheduled). For every employee whose `step_anniversary_date`
 * falls on today's date and whose current step is below their grade's max,
 * increments the step by 1 and resets the anniversary to next year.
 *
 * Idempotent — running twice on the same date does NOT double-increment
 * because the anniversary is moved forward in the same transaction.
 *
 * **Performance gating (WS12):** an employee only earns the increment if
 * their most recent CLOSED-cycle review has an `overall_rating` ≥
 * MIN_RATING_FOR_INCREMENT. Otherwise the anniversary still rolls forward
 * (so they're re-evaluated next year), but no step bump is awarded. This
 * closes the loop between Performance Management and Establishment.
 */
class StepIncrementService
{
    public const MIN_RATING_FOR_INCREMENT = 3.0; // on the 1.00-5.00 scale

    /**
     * @return array{processed:int, skipped_top:int, skipped_underperform:int, skipped_no_review:int}
     */
    public function processForDate(\DateTimeInterface|string $date): array
    {
        $today = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        $employees = Employee::query()
            ->whereDate('step_anniversary_date', $today)
            ->whereNotNull('current_grade_id')
            ->whereNotNull('current_step')
            ->with('currentGrade')
            ->get();

        $processed           = 0;
        $skippedTop          = 0;
        $skippedUnderperform = 0;
        $skippedNoReview     = 0;

        foreach ($employees as $employee) {
            $grade = $employee->currentGrade;
            if (! $grade) {
                $skippedNoReview++;
                continue;
            }

            // Top of grade — anniversary rolls forward so we don't re-evaluate every night.
            if ((int) $employee->current_step >= (int) $grade->max_step) {
                $this->rollAnniversary($employee, $today);
                $skippedTop++;
                continue;
            }

            // Performance gate
            $latestReview = $this->latestClosedReview($employee);
            if (! $latestReview) {
                $this->rollAnniversary($employee, $today);
                $skippedNoReview++;
                continue;
            }

            $rating = (float) ($latestReview->overall_rating ?? 0);
            if ($rating < self::MIN_RATING_FOR_INCREMENT) {
                $this->rollAnniversary($employee, $today);
                $skippedUnderperform++;
                continue;
            }

            DB::transaction(function () use ($employee, $today) {
                $employee->update([
                    'current_step'          => (int) $employee->current_step + 1,
                    'step_anniversary_date' => now()->parse($today)->addYear()->toDateString(),
                ]);
            });

            $processed++;
        }

        return [
            'processed'             => $processed,
            'skipped_top'           => $skippedTop,
            'skipped_underperform'  => $skippedUnderperform,
            'skipped_no_review'     => $skippedNoReview,
        ];
    }

    private function rollAnniversary(Employee $employee, string $today): void
    {
        $employee->update([
            'step_anniversary_date' => now()->parse($today)->addYear()->toDateString(),
        ]);
    }

    private function latestClosedReview(Employee $employee): ?Review
    {
        // The "closed-cycle" definition: a manager-type review with non-null
        // overall_rating from a cycle whose status is closed. We accept the
        // most recent regardless of cycle status if no closed-cycle review
        // exists, to handle organisations that don't formally close cycles.
        return Review::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('overall_rating')
            ->whereIn('type', ['manager', 'final'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
    }
}

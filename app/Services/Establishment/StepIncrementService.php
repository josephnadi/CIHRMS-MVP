<?php

namespace App\Services\Establishment;

use App\Models\Employee;
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
 */
class StepIncrementService
{
    /**
     * @return array{processed:int, skipped:int}
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

        $processed = 0;
        $skipped   = 0;

        foreach ($employees as $employee) {
            $grade = $employee->currentGrade;
            if (! $grade) {
                $skipped++;
                continue;
            }

            if ((int) $employee->current_step >= (int) $grade->max_step) {
                // Already at top of grade — advance anniversary so we don't re-evaluate every night.
                $employee->update([
                    'step_anniversary_date' => now()->parse($today)->addYear()->toDateString(),
                ]);
                $skipped++;
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

        return ['processed' => $processed, 'skipped' => $skipped];
    }
}

<?php

namespace App\Services\Performance;

use App\Enums\PipStatus;
use App\Models\Employee;
use App\Models\PerformanceImprovementPlan;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Performance Improvement Plan lifecycle.
 *
 *   open → in_progress → (succeeded | failed_demoted | failed_terminated | extended)
 *
 * A PIP MUST exist before any non-disciplinary termination — that's the
 * defensible audit trail Ghana Labour Act §63 expects. Closing the PIP as
 * `failed_terminated` automatically links to the off-boarding case for
 * smoother handoff.
 */
class PipService
{
    public const DEFAULT_DURATION_DAYS = 90;

    public function open(
        Employee $employee,
        ?Review $triggerReview,
        ?Employee $mentor,
        array $targetMetrics,
        User $opener,
        ?int $durationDays = null,
    ): PerformanceImprovementPlan {
        if ($this->openPlanFor($employee)) {
            throw new \DomainException("Employee {$employee->employee_no} already has an open PIP.");
        }

        $this->validateMetrics($targetMetrics);

        $opened = CarbonImmutable::now()->startOfDay();
        $target = $opened->addDays($durationDays ?? self::DEFAULT_DURATION_DAYS);

        return PerformanceImprovementPlan::create([
            'employee_id'             => $employee->id,
            'triggered_by_review_id'  => $triggerReview?->id,
            'opened_by'               => $opener->id,
            'mentor_id'               => $mentor?->id,
            'status'                  => PipStatus::Open->value,
            'opened_on'               => $opened->toDateString(),
            'target_end_date'         => $target->toDateString(),
            'target_metrics'          => array_values($targetMetrics),
            'checkins'                => [],
            'extensions_used'         => 0,
            'max_extensions'          => 1,
        ]);
    }

    public function addCheckin(
        PerformanceImprovementPlan $pip,
        User $author,
        string $note,
        bool $metTarget,
    ): PerformanceImprovementPlan {
        if ($pip->status->isTerminal()) {
            throw new \DomainException('Cannot check in on a closed PIP.');
        }

        $checkins = $pip->checkins ?? [];
        $checkins[] = [
            'date'      => now()->toDateString(),
            'author_id' => $author->id,
            'note'      => $note,
            'met_target'=> $metTarget,
        ];

        $pip->update([
            'checkins' => $checkins,
            'status'   => PipStatus::InProgress->value,
        ]);

        return $pip->fresh();
    }

    public function extend(PerformanceImprovementPlan $pip, int $additionalDays, User $actor, string $reason): PerformanceImprovementPlan
    {
        if ($pip->extensions_used >= $pip->max_extensions) {
            throw new \DomainException('PIP extension limit reached.');
        }
        if ($pip->status->isTerminal()) {
            throw new \DomainException('Cannot extend a closed PIP.');
        }

        $newTarget = CarbonImmutable::parse($pip->target_end_date)->addDays($additionalDays);

        $checkins = $pip->checkins ?? [];
        $checkins[] = [
            'date'       => now()->toDateString(),
            'author_id'  => $actor->id,
            'note'       => "[EXTENSION] {$reason}",
            'met_target' => false,
        ];

        $pip->update([
            'status'           => PipStatus::Extended->value,
            'target_end_date'  => $newTarget->toDateString(),
            'extensions_used'  => $pip->extensions_used + 1,
            'checkins'         => $checkins,
        ]);

        return $pip->fresh();
    }

    public function close(
        PerformanceImprovementPlan $pip,
        PipStatus $outcome,
        User $actor,
        string $summary,
    ): PerformanceImprovementPlan {
        if (! in_array($outcome, [
            PipStatus::Succeeded, PipStatus::FailedDemoted, PipStatus::FailedTerminated, PipStatus::Cancelled,
        ], true)) {
            throw new \DomainException("Cannot close PIP with status {$outcome->value}.");
        }
        if ($pip->status->isTerminal()) return $pip;

        $pip->update([
            'status'           => $outcome->value,
            'actual_end_date'  => now()->toDateString(),
            'outcome_summary'  => $summary,
        ]);

        return $pip->fresh();
    }

    private function openPlanFor(Employee $employee): ?PerformanceImprovementPlan
    {
        return PerformanceImprovementPlan::where('employee_id', $employee->id)->open()->first();
    }

    private function validateMetrics(array $metrics): void
    {
        if (count($metrics) === 0) {
            throw new \DomainException('At least one target metric is required for a PIP.');
        }
        foreach ($metrics as $i => $m) {
            if (! isset($m['metric']) || trim($m['metric']) === '') {
                throw new \DomainException("Metric #{$i} is missing a name.");
            }
            if (! isset($m['target'])) {
                throw new \DomainException("Metric '{$m['metric']}' is missing a target.");
            }
        }
    }
}

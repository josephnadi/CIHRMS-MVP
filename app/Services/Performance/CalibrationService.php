<?php

namespace App\Services\Performance;

use App\Enums\CalibrationStatus;
use App\Models\CalibrationAdjustment;
use App\Models\CalibrationSession;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Calibration session orchestrator.
 *
 *   open → in_progress → locked → applied
 *
 * Adjustments record original_rating and adjusted_rating side-by-side
 * with the calibrator's reason; on `apply`, adjusted ratings flow back
 * to the underlying Review rows. Locking is dual-control: the facilitator
 * (typically an HR business partner) locks, but a different user with
 * `performance.calibrate_apply` must apply the changes.
 */
class CalibrationService
{
    /** Default Ghana public-service distribution: 10/25/40/20/5 (high → low). */
    public const DEFAULT_DISTRIBUTION = [
        '5' => 0.10, '4' => 0.25, '3' => 0.40, '2' => 0.20, '1' => 0.05,
    ];

    public function open(
        ReviewCycle $cycle,
        ?int $departmentId,
        User $facilitator,
        ?array $targetDistribution = null,
    ): CalibrationSession {
        return CalibrationSession::create([
            'cycle_id'             => $cycle->id,
            'department_id'        => $departmentId,
            'status'               => CalibrationStatus::InProgress->value,
            'facilitated_by'       => $facilitator->id,
            'opened_at'            => now(),
            'target_distribution'  => $targetDistribution ?? self::DEFAULT_DISTRIBUTION,
        ]);
    }

    public function recordAdjustment(
        CalibrationSession $session,
        Review $review,
        float $adjustedRating,
        ?string $reason,
        User $adjuster,
    ): CalibrationAdjustment {
        if ($session->status !== CalibrationStatus::InProgress) {
            throw new \DomainException('Session is not in progress.');
        }

        $original = (float) ($review->overall_rating ?? 0);

        return CalibrationAdjustment::updateOrCreate(
            ['session_id' => $session->id, 'review_id' => $review->id],
            [
                'original_rating' => $original,
                'adjusted_rating' => round(max(1.0, min(5.0, $adjustedRating)), 2),
                'reason'          => $reason,
                'adjusted_by'     => $adjuster->id,
                'adjusted_at'     => now(),
            ],
        );
    }

    public function lock(CalibrationSession $session, User $locker): CalibrationSession
    {
        if ($session->status !== CalibrationStatus::InProgress) {
            throw new \DomainException('Only in-progress sessions can be locked.');
        }
        $session->update([
            'status'    => CalibrationStatus::Locked->value,
            'locked_at' => now(),
        ]);
        return $session->fresh();
    }

    /**
     * Reopen a locked session — sets status back to InProgress so the
     * facilitator can adjust ratings again. Refuses if the session has
     * already been applied (Review rows are written by then; reopening
     * after that would create a stale-state risk).
     */
    public function reopen(CalibrationSession $session, User $reopener): CalibrationSession
    {
        if ($session->status !== CalibrationStatus::Locked) {
            throw new \DomainException('Only locked sessions can be reopened. Applied sessions are final.');
        }
        $session->update([
            'status'    => CalibrationStatus::InProgress->value,
            'locked_at' => null,
        ]);
        return $session->fresh();
    }

    public function apply(CalibrationSession $session, User $applier): CalibrationSession
    {
        if ($session->status !== CalibrationStatus::Locked) {
            throw new \DomainException('Only locked sessions can be applied.');
        }
        if ($session->facilitated_by === $applier->id) {
            throw new \DomainException('Dual-control: applier must differ from facilitator.');
        }

        return DB::transaction(function () use ($session, $applier) {
            $session->loadMissing('adjustments');
            foreach ($session->adjustments as $adj) {
                Review::where('id', $adj->review_id)->update([
                    'overall_rating' => $adj->adjusted_rating,
                ]);
            }

            $session->update([
                'status'     => CalibrationStatus::Applied->value,
                'applied_at' => now(),
                'applied_by' => $applier->id,
            ]);

            return $session->fresh();
        });
    }

    /**
     * Compute the actual distribution of (rounded) ratings in the session,
     * for comparison against `target_distribution` in the UI.
     *
     * @return array<string, float> rating-band → proportion (0..1)
     */
    public function actualDistribution(CalibrationSession $session): array
    {
        $reviewIds = $session->adjustments->pluck('review_id')->all();
        if (empty($reviewIds)) return [];

        $reviews = Review::whereIn('id', $reviewIds)->whereNotNull('overall_rating')->get();
        $total = $reviews->count();
        if ($total === 0) return [];

        $bands = [];
        foreach ($reviews as $r) {
            $band = (string) (int) round((float) $r->overall_rating);
            $bands[$band] = ($bands[$band] ?? 0) + 1;
        }

        return collect($bands)->map(fn ($n) => round($n / $total, 4))->all();
    }
}

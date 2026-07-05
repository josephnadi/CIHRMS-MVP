<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Services\Finance\SequenceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Applies an across-the-board salary revision (CIHRM's "Revised Basic Salary
 * (10%)"). Because grade-step base salaries are effective-dated, a revision is
 * a new set of effective-dated rates: for every currently-open grade-step rate
 * it closes the old row and writes a new one (old × factor) effective from the
 * revision date. Future payroll runs then read the new figure automatically and
 * historical runs are untouched.
 *
 * Scope: institute-wide `percentage`, with optional per-grade overrides
 * (`[grade_id => percentage]`).
 */
class SalaryRevisionService
{
    public function __construct(private readonly SequenceService $sequences) {}

    /**
     * Preview the effect without persisting.
     * @return array<int, array{grade_id:int, grade_code:string, step:int, rate:float, old:float, new:float}>
     */
    public function preview(float $percentage, string|\DateTimeInterface $effectiveFrom, array $gradeOverrides = []): array
    {
        $rows = [];
        foreach ($this->openSteps() as $step) {
            $rate = $this->rateFor((int) $step->grade_id, $percentage, $gradeOverrides);
            $old  = (float) $step->base_salary;
            $rows[] = [
                'grade_id'   => (int) $step->grade_id,
                'grade_code' => $step->grade?->code ?? (string) $step->grade_id,
                'step'       => (int) $step->step,
                'rate'       => $rate,
                'old'        => round($old, 2),
                'new'        => round($old * (1 + $rate / 100), 2),
            ];
        }

        return $rows;
    }

    public function apply(
        float $percentage,
        string|\DateTimeInterface $effectiveFrom,
        string $scope = 'institute',
        array $gradeOverrides = [],
        ?User $actor = null,
        ?string $notes = null,
    ): SalaryRevision {
        $effective = CarbonImmutable::parse($effectiveFrom instanceof \DateTimeInterface ? $effectiveFrom->format('Y-m-d') : $effectiveFrom);
        $closeDate = $effective->subDay();

        return DB::transaction(function () use ($percentage, $effective, $closeDate, $scope, $gradeOverrides, $actor, $notes) {
            $steps = $this->openSteps();

            // Guard: a revision already effective on this exact date would create
            // overlapping rates. Refuse rather than silently double-revise.
            $clash = GradeStep::whereNull('effective_to')
                ->whereDate('effective_from', $effective->toDateString())
                ->exists();
            if ($clash) {
                throw new DomainException("A rate already begins on {$effective->toDateString()}; choose a different effective date.");
            }

            $count = 0;
            foreach ($steps as $step) {
                $rate = $this->rateFor((int) $step->grade_id, $percentage, $gradeOverrides);
                if ($rate == 0.0) {
                    continue;
                }

                $newBase = round((float) $step->base_salary * (1 + $rate / 100), 2);

                // Close the old open rate the day before the revision.
                $step->update(['effective_to' => $closeDate->toDateString()]);

                // Write the new effective-dated rate.
                GradeStep::create([
                    'grade_id'       => $step->grade_id,
                    'step'           => $step->step,
                    'base_salary'    => $newBase,
                    'currency'       => $step->currency ?? 'GHS',
                    'effective_from' => $effective->toDateString(),
                    'effective_to'   => null,
                ]);
                $count++;
            }

            return SalaryRevision::create([
                'reference'       => $this->nextReference($effective),
                'scope'           => $scope,
                'percentage'      => $percentage,
                'effective_from'  => $effective->toDateString(),
                'grade_overrides' => $gradeOverrides ?: null,
                'affected_count'  => $count,
                'notes'           => $notes,
                'applied_by'      => $actor?->id,
            ]);
        });
    }

    /** Currently-open grade-step rates (the ones a revision replaces). */
    private function openSteps()
    {
        return GradeStep::query()->with('grade:id,code')->whereNull('effective_to')->get();
    }

    /** Per-grade override rate if present, else the institute-wide percentage. */
    private function rateFor(int $gradeId, float $percentage, array $overrides): float
    {
        return array_key_exists($gradeId, $overrides) ? (float) $overrides[$gradeId] : $percentage;
    }

    private function nextReference(CarbonImmutable $effective): string
    {
        $year = $effective->format('Y');
        return sprintf('SR-%s-%04d', $year, $this->sequences->next("salary_revision:{$year}"));
    }
}

<?php

namespace App\Services\Payroll;

use App\Enums\DeductionType;
use App\Models\Deduction;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Priority-ordered, net-floor-aware deduction aggregator.
 *
 * Court-ordered garnishments execute first. Voluntary deductions only execute
 * to the extent that net pay does not fall below a configurable minimum
 * (default: 1/3 of gross — a common public-sector "take-home" floor).
 */
class DeductionAggregator
{
    public function __construct(private readonly float $minimumTakeHomeRatio = 0.333) {}

    /**
     * @param float $netAfterStatutory  Gross - SSNIT employee - PAYE
     * @return array{
     *     total: float,
     *     applied: array<int, array{label:string, type:string, amount:float, priority:int}>,
     *     deferred: array<int, array{label:string, type:string, amount:float, reason:string}>
     * }
     */
    public function aggregate(Employee $employee, float $gross, float $netAfterStatutory, \DateTimeInterface|string $periodDate): array
    {
        /** @var Collection<int, Deduction> $items */
        $items = Deduction::where('employee_id', $employee->id)
            ->effectiveOn($periodDate)
            ->get();

        // Sort by enum-driven priority. Garnishments first, etc.
        $sorted = $items->sortBy(function (Deduction $d) {
            $enum = $d->type instanceof DeductionType ? $d->type : DeductionType::tryFrom((string) $d->type);
            return $enum?->priority() ?? 99;
        })->values();

        $floor   = round($gross * $this->minimumTakeHomeRatio, 2);
        $running = $netAfterStatutory;
        $applied = [];
        $deferred = [];

        foreach ($sorted as $item) {
            $raw = round($item->resolveAmount($gross), 2);
            if ($raw <= 0) continue;

            $remainingHeadroom = round($running - $floor, 2);

            // Court-ordered garnishments ignore the floor — they're statutory.
            $enum = $item->type instanceof DeductionType ? $item->type : DeductionType::tryFrom((string) $item->type);
            $isGarnishment = $enum === DeductionType::Garnishment;

            if (! $isGarnishment && $raw > $remainingHeadroom) {
                $partial = max($remainingHeadroom, 0);
                if ($partial > 0) {
                    $applied[] = $this->row($item, $partial);
                    $running -= $partial;
                }

                $deferredAmount = round($raw - $partial, 2);
                if ($deferredAmount > 0) {
                    $deferred[] = [
                        'label'  => $item->label,
                        'type'   => $enum?->value ?? 'other',
                        'amount' => $deferredAmount,
                        'reason' => 'Would breach take-home floor (1/3 of gross).',
                    ];
                }
                continue;
            }

            $applied[] = $this->row($item, $raw);
            $running -= $raw;
        }

        return [
            'total'    => round(collect($applied)->sum('amount'), 2),
            'applied'  => $applied,
            'deferred' => $deferred,
        ];
    }

    private function row(Deduction $d, float $amount): array
    {
        $enum = $d->type instanceof DeductionType ? $d->type : DeductionType::tryFrom((string) $d->type);

        return [
            'label'    => $d->label,
            'type'     => $enum?->value ?? 'other',
            'amount'   => round($amount, 2),
            'priority' => $enum?->priority() ?? 99,
        ];
    }
}

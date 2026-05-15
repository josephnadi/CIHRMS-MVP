<?php

namespace App\Services\Payroll;

use App\Models\TaxBracket;
use App\Services\PayrollCalculator;
use Carbon\CarbonImmutable;

/**
 * Pure, effective-dated PAYE calculator.
 *
 * Given a chargeable income and an effective date, returns the income-tax due
 * using the bracket table for that date. Falls back to the static brackets
 * baked into PayrollCalculator if the database hasn't been seeded yet.
 *
 * Invariant: same (chargeable, date) → same result, forever.
 * This is what makes historical payroll runs reproducible.
 */
class PayeCalculator
{
    /**
     * @return array{tax: float, bands: array<int, array<string, mixed>>}
     */
    public function calculate(float $chargeable, \DateTimeInterface|string $effectiveOn, string $cadence = 'monthly'): array
    {
        $brackets = $this->loadBrackets($effectiveOn, $cadence);

        if ($chargeable <= 0 || empty($brackets)) {
            return ['tax' => 0.0, 'bands' => []];
        }

        $tax  = 0.0;
        $rows = [];
        $previousUpper = 0.0;

        foreach ($brackets as $band) {
            $upper = $band['upper'];
            $rate  = (float) $band['rate'];

            if ($upper === null) {
                $taxableInBand = max($chargeable - $previousUpper, 0);
                if ($taxableInBand > 0) {
                    $tax += $taxableInBand * $rate;
                    $rows[] = $this->bandRow($previousUpper, null, $rate, $taxableInBand);
                }
                break;
            }

            $bandWidth = $upper - $previousUpper;

            if ($chargeable > $upper) {
                $tax += $bandWidth * $rate;
                $rows[] = $this->bandRow($previousUpper, $upper, $rate, $bandWidth);
                $previousUpper = $upper;
                continue;
            }

            $taxableInBand = $chargeable - $previousUpper;
            if ($taxableInBand > 0) {
                $tax += $taxableInBand * $rate;
                $rows[] = $this->bandRow($previousUpper, $upper, $rate, $taxableInBand);
            }
            break;
        }

        return ['tax' => round($tax, 2), 'bands' => $rows];
    }

    /**
     * @return array<int, array{upper: ?float, rate: float}>
     */
    private function loadBrackets(\DateTimeInterface|string $effectiveOn, string $cadence): array
    {
        $date = $effectiveOn instanceof \DateTimeInterface
            ? CarbonImmutable::instance($effectiveOn)
            : CarbonImmutable::parse($effectiveOn);

        $rows = TaxBracket::effectiveOn($date, 'GH', $cadence)->get();

        if ($rows->isNotEmpty()) {
            return $rows->map(fn (TaxBracket $b) => [
                'upper' => $b->upper_bound !== null ? (float) $b->upper_bound : null,
                'rate'  => (float) $b->rate,
            ])->all();
        }

        // Fallback to the static 2026 monthly brackets baked into the legacy calculator
        return PayrollCalculator::PAYE_BRACKETS_MONTHLY;
    }

    private function bandRow(float $lower, ?float $upper, float $rate, float $taxableInBand): array
    {
        return [
            'lower'  => round($lower, 2),
            'upper'  => $upper === null ? null : round($upper, 2),
            'rate'   => $rate,
            'amount' => round($taxableInBand, 2),
            'tax'    => round($taxableInBand * $rate, 2),
            'label'  => $upper === null
                ? sprintf('Above GHS %s @ %s%%', number_format($lower, 2), number_format($rate * 100, 1))
                : sprintf('GHS %s – %s @ %s%%', number_format($lower, 2), number_format($upper, 2), number_format($rate * 100, 1)),
        ];
    }
}

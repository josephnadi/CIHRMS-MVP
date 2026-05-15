<?php

namespace App\Services\Payroll;

use App\Models\StatutoryRate;

/**
 * SSNIT Tier-1 calculator with NHIA split disclosure.
 *
 * Ghana social-security flow (2026):
 *   - Employer remits 13% of basic to SSNIT
 *   - Employee contributes 5.5% of basic (deducted from gross)
 *   - SSNIT internally routes 2.5% to NHIA, leaving 11% as the true Tier-1 pension
 *   - All capped at MAX_INSURABLE_EARNINGS (GHS 61,000/month at last seeded rate)
 *
 * The calculator returns the gross employer line plus the NHIA-split sub-line
 * so the statutory return generators can produce both files correctly.
 */
class SsnitCalculator
{
    /**
     * @return array{
     *     base: float,
     *     employee: float,
     *     employer: float,
     *     nhia_split: float,
     *     tier1_net: float
     * }
     */
    public function calculate(float $basic, \DateTimeInterface|string $effectiveOn): array
    {
        $employerRate    = StatutoryRate::lookup(StatutoryRate::SSNIT_EMPLOYER, $effectiveOn);
        $employeeRate    = StatutoryRate::lookup(StatutoryRate::SSNIT_EMPLOYEE, $effectiveOn);
        $nhiaSplitRate   = StatutoryRate::lookup(StatutoryRate::NHIA_SPLIT,     $effectiveOn);
        $maxInsurable    = StatutoryRate::lookup(StatutoryRate::MAX_INSURABLE_EARNINGS, $effectiveOn);

        $base = min(max($basic, 0), $maxInsurable);

        $employer  = round($base * $employerRate, 2);
        $employee  = round($base * $employeeRate, 2);
        $nhiaSplit = round($base * $nhiaSplitRate, 2);
        $tier1Net  = round($employer - $nhiaSplit, 2);

        return [
            'base'       => round($base, 2),
            'employee'   => $employee,
            'employer'   => $employer,
            'nhia_split' => $nhiaSplit,
            'tier1_net'  => $tier1Net,
        ];
    }
}

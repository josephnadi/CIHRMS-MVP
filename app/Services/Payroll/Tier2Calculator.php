<?php

namespace App\Services\Payroll;

use App\Models\StatutoryRate;

/**
 * Tier-2 occupational pension calculator.
 *
 * In Ghana, Tier-2 is a mandatory 5% employer contribution since the National
 * Pensions Act 2008 (Act 766). It is paid into a privately managed corporate
 * trustee, not SSNIT. Each employee can be linked to a different trustee, so
 * the statutory-return generator groups by trustee.
 */
class Tier2Calculator
{
    /**
     * @return array{employer: float}
     */
    public function calculate(float $basic, \DateTimeInterface|string $effectiveOn): array
    {
        $rate = StatutoryRate::lookup(StatutoryRate::TIER2_EMPLOYER, $effectiveOn);
        $maxInsurable = StatutoryRate::lookup(StatutoryRate::MAX_INSURABLE_EARNINGS, $effectiveOn);

        $base = min(max($basic, 0), $maxInsurable);
        $employer = round($base * $rate, 2);

        return ['employer' => $employer];
    }
}

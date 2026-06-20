<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRate;

/**
 * Tier-3 voluntary pension calculator.
 *
 * Tier-3 (Pensions Act 2008, Act 766) is a voluntary employee contribution,
 * elected as a percentage of basic. It is tax-relieved up to a combined Tier-2 +
 * Tier-3 ceiling of 16.5% of basic; since Tier-2 mandatory is 5%, up to 11.5% of
 * basic of Tier-3 reduces chargeable income, and any elected excess is still
 * deducted but taxed.
 */
class Tier3Calculator
{
    /**
     * @return array{employee: float, relieved: float, excess: float}
     */
    public function calculate(float $basic, float $rate, \DateTimeInterface|string $effectiveOn): array
    {
        if ($basic <= 0 || $rate <= 0) {
            return ['employee' => 0.0, 'relieved' => 0.0, 'excess' => 0.0];
        }

        $elected = round($basic * $rate, 2);

        $cap   = StatutoryRate::lookup(StatutoryRate::TIER3_MAX_COMBINED, $effectiveOn); // 0.165
        $tier2 = StatutoryRate::lookup(StatutoryRate::TIER2_EMPLOYER, $effectiveOn);      // 0.05

        $availableRelief = round(max(0.0, $cap - $tier2) * $basic, 2);
        $relieved = round(min($elected, $availableRelief), 2);
        $excess   = round($elected - $relieved, 2);

        return ['employee' => $elected, 'relieved' => $relieved, 'excess' => $excess];
    }
}

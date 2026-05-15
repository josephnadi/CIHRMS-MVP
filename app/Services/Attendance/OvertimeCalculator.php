<?php

namespace App\Services\Attendance;

/**
 * Ghana Labour Act 2003 (Act 651) overtime calculator.
 *
 * §35 Overtime work:
 *   - Weekday OT (beyond 8h):  1.5×
 *   - Weekend / public holiday: 2×
 *   - Beyond 12h on any day:    2× (deep OT)
 *
 * The calculator returns the *premium hours* (the multiplier-weighted excess
 * over straight time), not the raw clock hours. Payroll uses these against
 * the hourly rate derived from the basic salary.
 */
class OvertimeCalculator
{
    private const FULL_DAY_HOURS = 8.0;
    private const DEEP_OT_HOURS  = 12.0;

    private const WEEKDAY_OT_MULTIPLIER  = 1.5;
    private const PREMIUM_OT_MULTIPLIER  = 2.0;

    /**
     * @return array{
     *     standard:float,          straight-time hours (no premium)
     *     weekday_15x:float,       premium hours @ 1.5×
     *     premium_2x:float,        premium hours @ 2× (weekend/holiday or deep)
     *     total:float              sum of premium-equivalent hours (for payroll)
     * }
     */
    public function calculateForDay(float $hoursWorked, bool $isWeekend, bool $isHoliday): array
    {
        $h = max(0.0, $hoursWorked);

        if ($isHoliday || $isWeekend) {
            // Every worked hour on a non-working day pays the 2× premium.
            return [
                'standard'    => 0.0,
                'weekday_15x' => 0.0,
                'premium_2x'  => $h,
                'total'       => round($h * self::PREMIUM_OT_MULTIPLIER, 2),
            ];
        }

        $standard = min($h, self::FULL_DAY_HOURS);

        if ($h <= self::FULL_DAY_HOURS) {
            return [
                'standard'    => round($standard, 2),
                'weekday_15x' => 0.0,
                'premium_2x'  => 0.0,
                'total'       => 0.0,
            ];
        }

        $overtime = $h - self::FULL_DAY_HOURS;
        $deep     = max(0.0, $h - self::DEEP_OT_HOURS);
        $normalOT = $overtime - $deep;

        return [
            'standard'    => round($standard, 2),
            'weekday_15x' => round($normalOT, 2),
            'premium_2x'  => round($deep, 2),
            'total'       => round($normalOT * self::WEEKDAY_OT_MULTIPLIER + $deep * self::PREMIUM_OT_MULTIPLIER, 2),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often a `FeeProduct` recurs. Used by the admin UI to decide which
 * period_label format the operator picks (`2026`, `2026-S1`, `2026-04`),
 * and by `BillingRunService` for the run's audit trail.
 *
 *  - Once      — single charge (graduation fee, library card replacement)
 *  - Annual    — once per calendar year (annual member dues)
 *  - Semester  — once per academic semester (term tuition)
 *  - Monthly   — recurring monthly (rare; subscription-style)
 */
enum BillingCycle: string
{
    case Once     = 'once';
    case Annual   = 'annual';
    case Semester = 'semester';
    case Monthly  = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Once     => 'One-off',
            self::Annual   => 'Annual',
            self::Semester => 'Semester',
            self::Monthly  => 'Monthly',
        };
    }

    /**
     * Default period label the admin UI suggests for this cycle when an
     * operator clicks "new billing run". They can override per cycle.
     */
    public function defaultPeriodLabel(\DateTimeInterface $now): string
    {
        return match ($this) {
            self::Once, self::Annual => $now->format('Y'),
            self::Semester           => $now->format('Y') . (((int) $now->format('n')) < 7 ? '-S1' : '-S2'),
            self::Monthly            => $now->format('Y-m'),
        };
    }
}

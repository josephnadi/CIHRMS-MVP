<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FiscalPeriod;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class PeriodResolver
{
    /**
     * Resolve a date to its monthly fiscal period (1–12). The Adjustment
     * period (13) is never resolved by date — it is targeted explicitly.
     * Returns null when no period is defined for the date.
     */
    public function resolveForDate(DateTimeInterface|string $date): ?FiscalPeriod
    {
        $day = ($date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date))->toDateString();

        return FiscalPeriod::query()
            ->where('period_no', '<=', 12)
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            ->first();
    }
}

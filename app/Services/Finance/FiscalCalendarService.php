<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

class FiscalCalendarService
{
    /**
     * Idempotently create a calendar fiscal year (Jan–Dec) with 12 monthly
     * periods plus a period-13 "Adjustment" bucket. All periods default Open.
     */
    public function ensureYear(int $year): FiscalYear
    {
        $fiscalYear = FiscalYear::updateOrCreate(
            ['year' => $year],
            [
                'status'    => FiscalPeriodStatus::Open->value,
                'starts_on' => sprintf('%04d-01-01', $year),
                'ends_on'   => sprintf('%04d-12-31', $year),
            ],
        );

        for ($month = 1; $month <= 12; $month++) {
            $start = CarbonImmutable::create($year, $month, 1);
            $end   = $start->endOfMonth();

            FiscalPeriod::updateOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'period_no' => $month],
                [
                    'name'      => $start->format('F Y'),
                    'starts_on' => $start->toDateString(),
                    'ends_on'   => $end->toDateString(),
                    'status'    => FiscalPeriodStatus::Open->value,
                ],
            );
        }

        // Period 13 — Adjustment. Dated at year-end as a sentinel; it is never
        // resolved by date (PeriodResolver only considers periods 1–12).
        FiscalPeriod::updateOrCreate(
            ['fiscal_year_id' => $fiscalYear->id, 'period_no' => 13],
            [
                'name'      => "Adjustment {$year}",
                'starts_on' => sprintf('%04d-12-31', $year),
                'ends_on'   => sprintf('%04d-12-31', $year),
                'status'    => FiscalPeriodStatus::Open->value,
            ],
        );

        return $fiscalYear->fresh();
    }
}

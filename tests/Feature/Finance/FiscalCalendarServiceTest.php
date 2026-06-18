<?php

declare(strict_types=1);

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Services\Finance\FiscalCalendarService;

it('generates 13 periods (12 months + adjustment) for a year, idempotently', function () {
    $svc = app(FiscalCalendarService::class);

    $year = $svc->ensureYear(2026);
    expect($year->periods()->count())->toBe(13);

    $jan = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
    expect($jan->name)->toBe('January 2026')
        ->and($jan->starts_on->toDateString())->toBe('2026-01-01')
        ->and($jan->ends_on->toDateString())->toBe('2026-01-31');

    $dec = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 12)->firstOrFail();
    expect($dec->ends_on->toDateString())->toBe('2026-12-31');

    $adj = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 13)->firstOrFail();
    expect($adj->name)->toBe('Adjustment 2026');

    // Idempotent — second call does not duplicate.
    $svc->ensureYear(2026);
    expect(FiscalPeriod::where('fiscal_year_id', $year->id)->count())->toBe(13)
        ->and(FiscalYear::where('year', 2026)->count())->toBe(1);
});

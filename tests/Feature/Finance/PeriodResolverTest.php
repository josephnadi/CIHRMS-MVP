<?php

declare(strict_types=1);

use App\Models\FiscalPeriod;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodResolver;
use Carbon\CarbonImmutable;

beforeEach(fn () => app(FiscalCalendarService::class)->ensureYear(2026));

it('resolves a date to its calendar-month period', function () {
    $period = app(PeriodResolver::class)->resolveForDate('2026-06-15');
    expect($period)->toBeInstanceOf(FiscalPeriod::class)
        ->and($period->period_no)->toBe(6)
        ->and($period->name)->toBe('June 2026');
});

it('resolves Dec 31 to December (period 12), never the adjustment period 13', function () {
    $period = app(PeriodResolver::class)->resolveForDate(CarbonImmutable::create(2026, 12, 31));
    expect($period->period_no)->toBe(12);
});

it('returns null when no period is defined for the date', function () {
    expect(app(PeriodResolver::class)->resolveForDate('2099-03-03'))->toBeNull();
});

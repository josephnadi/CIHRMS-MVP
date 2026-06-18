<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;

it('stores a year with periods and casts status', function () {
    $year = FiscalYear::create([
        'year' => 2026, 'status' => 'open',
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
    ]);

    $period = FiscalPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 1, 'name' => 'January 2026',
        'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'open',
    ]);

    expect($period->fresh()->status)->toBe(FiscalPeriodStatus::Open)
        ->and($period->fiscalYear->year)->toBe(2026)
        ->and($year->periods()->count())->toBe(1);
});

it('enforces a unique (fiscal_year_id, period_no)', function () {
    $year = FiscalYear::create(['year' => 2027, 'status' => 'open', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    FiscalPeriod::create(['fiscal_year_id' => $year->id, 'period_no' => 1, 'name' => 'Jan', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'status' => 'open']);

    expect(fn () => FiscalPeriod::create(['fiscal_year_id' => $year->id, 'period_no' => 1, 'name' => 'Dup', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'status' => 'open']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

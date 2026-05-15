<?php

use App\Services\Attendance\OvertimeCalculator;

beforeEach(fn () => $this->calc = new OvertimeCalculator());

it('returns zero premium for a normal 8h weekday', function () {
    $r = $this->calc->calculateForDay(hoursWorked: 8.0, isWeekend: false, isHoliday: false);
    expect($r['total'])->toBe(0.0);
    expect($r['standard'])->toBe(8.0);
});

it('applies 1.5× for 2h overtime on a weekday', function () {
    $r = $this->calc->calculateForDay(hoursWorked: 10.0, isWeekend: false, isHoliday: false);
    expect($r['weekday_15x'])->toBe(2.0);
    expect($r['total'])->toBe(3.0); // 2 × 1.5
});

it('applies 2× for any hour worked on a weekend', function () {
    $r = $this->calc->calculateForDay(hoursWorked: 6.0, isWeekend: true, isHoliday: false);
    expect($r['premium_2x'])->toBe(6.0);
    expect($r['total'])->toBe(12.0); // 6 × 2
});

it('applies 2× for any hour worked on a public holiday', function () {
    $r = $this->calc->calculateForDay(hoursWorked: 8.0, isWeekend: false, isHoliday: true);
    expect($r['premium_2x'])->toBe(8.0);
    expect($r['total'])->toBe(16.0);
});

it('escalates beyond 12h to 2× deep-OT', function () {
    $r = $this->calc->calculateForDay(hoursWorked: 14.0, isWeekend: false, isHoliday: false);
    // 4h of 1.5× + 2h of 2×
    expect($r['weekday_15x'])->toBe(4.0);
    expect($r['premium_2x'])->toBe(2.0);
    expect($r['total'])->toBe(10.0); // 4 × 1.5 + 2 × 2
});

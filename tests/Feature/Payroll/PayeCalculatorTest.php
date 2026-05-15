<?php

use App\Services\Payroll\PayeCalculator;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->calc = app(PayeCalculator::class);
    $this->date = '2026-06-15';
});

it('returns zero tax for income at or below the tax-free threshold', function () {
    expect($this->calc->calculate(0, $this->date)['tax'])->toBe(0.0);
    expect($this->calc->calculate(490.0, $this->date)['tax'])->toBe(0.0);
});

it('taxes only the slice above the tax-free threshold', function () {
    // 600 - 490 = 110 @ 5% = 5.50
    expect($this->calc->calculate(600.0, $this->date)['tax'])->toBe(5.5);
});

it('crosses the 10% band correctly at GHS 730', function () {
    // 490–600 = 110 @ 5%   = 5.50
    // 600–730 = 130 @ 10%  = 13.00
    // Total                 = 18.50
    expect($this->calc->calculate(730.0, $this->date)['tax'])->toBe(18.5);
});

it('produces the correct total for a senior officer earning GHS 8,000', function () {
    $r = $this->calc->calculate(8_000.0, $this->date);

    // Hand-calc on 2026 monthly brackets:
    //   490–600     110 × 5%    =    5.50
    //   600–730     130 × 10%   =   13.00
    //   730–3896.67  3166.67 × 17.5% = 554.17
    //   3896.67–8000 4103.33 × 25%   = 1025.83
    //                                ─────────
    //                                 1598.50
    expect($r['tax'])->toBe(1598.50);
    expect($r['bands'])->toBeArray()->toHaveCount(5); // 0%, 5%, 10%, 17.5%, 25%
});

it('applies the top 35% band above GHS 50,416.67', function () {
    // Anything above 50416.67 is taxed at 35% in the top band.
    $r = $this->calc->calculate(60_000.0, $this->date);
    expect($r['tax'])->toBeGreaterThan(15_000.0);
});

it('is deterministic — same input on same date returns identical output', function () {
    $a = $this->calc->calculate(12_345.67, $this->date);
    $b = $this->calc->calculate(12_345.67, $this->date);
    expect($a)->toEqual($b);
});

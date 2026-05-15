<?php

use App\Services\Payroll\SsnitCalculator;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->calc = app(SsnitCalculator::class);
    $this->date = '2026-06-15';
});

it('computes 5.5% employee and 13% employer on basic', function () {
    $r = $this->calc->calculate(10_000.0, $this->date);

    expect($r['base'])->toBe(10_000.0);
    expect($r['employee'])->toBe(550.0);     // 5.5%
    expect($r['employer'])->toBe(1_300.0);   // 13%
});

it('splits 2.5% of basic to NHIA out of the employer 13%', function () {
    $r = $this->calc->calculate(10_000.0, $this->date);

    expect($r['nhia_split'])->toBe(250.0);          // 2.5%
    expect($r['tier1_net'])->toBe(1_050.0);         // 13% - 2.5% = 10.5%
});

it('caps the SSNIT base at the Maximum Insurable Earnings (GHS 61,000)', function () {
    $r = $this->calc->calculate(80_000.0, $this->date);

    expect($r['base'])->toBe(61_000.0);
    expect($r['employee'])->toBe(3_355.0); // 5.5% × 61,000
});

<?php

use App\Enums\AmortizationMethod;
use App\Services\Loans\AmortizationCalculator;

beforeEach(function () {
    $this->calc = app(AmortizationCalculator::class);
});

it('does straight-line amortization with no interest', function () {
    $r = $this->calc->calculate(1_200, 12, 0, AmortizationMethod::StraightLine);

    expect($r['monthly_installment'])->toBe(100.0);
    expect($r['total_interest'])->toBe(0.0);
    expect($r['total_repayable'])->toBe(1_200.0);
    expect($r['schedule'])->toHaveCount(12);
    expect($r['schedule'][0]['scheduled_amount'])->toBe(100.0);
    expect($r['schedule'][11]['balance_after'])->toBe(0.0);
});

it('does reducing-balance with the PMT formula', function () {
    // 10,000 over 12 months @ 12% annual
    // PMT ≈ 888.49; verified against any standard amortization calculator
    $r = $this->calc->calculate(10_000, 12, 0.12, AmortizationMethod::ReducingBalance);

    expect($r['monthly_installment'])->toBeFloat()->toEqualWithDelta(888.49, 0.05);
    expect($r['total_interest'])->toBeFloat()->toEqualWithDelta(661.85, 0.5);
    expect($r['schedule'])->toHaveCount(12);

    // Sum of all installments should equal total_repayable (rounding-drift absorbed in last row)
    $sum = collect($r['schedule'])->sum('scheduled_amount');
    expect($sum)->toEqualWithDelta($r['total_repayable'], 0.01);

    // Final balance is exactly 0
    expect($r['schedule'][11]['balance_after'])->toBe(0.0);
});

it('does flat-rate amortization with even installments', function () {
    // 12,000 over 24 months @ 10% flat = 2400 total interest → 14400 total
    $r = $this->calc->calculate(12_000, 24, 0.10, AmortizationMethod::FlatRate);

    expect($r['total_interest'])->toBe(2_400.0);
    expect($r['total_repayable'])->toBe(14_400.0);
    expect($r['monthly_installment'])->toBe(600.0);

    // Sum must equal total_repayable to the penny
    $sum = collect($r['schedule'])->sum('scheduled_amount');
    expect($sum)->toEqualWithDelta(14_400.0, 0.01);
});

it('treats reducing-balance with rate=0 as straight-line', function () {
    $r = $this->calc->calculate(6_000, 6, 0, AmortizationMethod::ReducingBalance);
    expect($r['total_interest'])->toBe(0.0);
    expect($r['monthly_installment'])->toBe(1_000.0);
});

it('rejects invalid inputs', function () {
    expect(fn () => $this->calc->calculate(0, 12, 0.1, AmortizationMethod::ReducingBalance))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->calc->calculate(1000, 0, 0.1, AmortizationMethod::ReducingBalance))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->calc->calculate(1000, 12, -0.05, AmortizationMethod::ReducingBalance))
        ->toThrow(InvalidArgumentException::class);
});

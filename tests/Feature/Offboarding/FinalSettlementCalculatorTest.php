<?php

use App\Enums\ExitType;
use App\Services\Offboarding\FinalSettlementCalculator;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->calc = app(FinalSettlementCalculator::class);
    $this->date = '2026-06-30';
});

it('pays no gratuity or severance for dismissal-with-cause', function () {
    $r = $this->calc->compute(
        exitType:        ExitType::Dismissal,
        basicSalary:     5_000,
        yearsOfService:  6.0,
        accruedLeaveDays: 8,
        outstandingLoans: 0,
        effectiveDate:    $this->date,
    );

    expect($r['gratuity'])->toBe(0.0);
    expect($r['severance'])->toBe(0.0);
    // 8 days × (5000/22) ≈ 1818.18
    expect($r['leave_encashment'])->toBeFloat()->toBeGreaterThan(1_800)->toBeLessThan(1_840);
});

it('pays gratuity for retirement (1× basic per year of service)', function () {
    $r = $this->calc->compute(
        exitType:        ExitType::Retirement,
        basicSalary:     6_000,
        yearsOfService:  10.0,
        accruedLeaveDays: 0,
        outstandingLoans: 0,
        effectiveDate:    $this->date,
    );

    expect($r['gratuity'])->toBe(60_000.0); // 6000 × 1.0 × 10
    expect($r['severance'])->toBe(0.0);
});

it('pays severance for redundancy (Act 651 §31)', function () {
    // Default severance multiplier = 1.5 months per year
    $r = $this->calc->compute(
        exitType:        ExitType::Redundancy,
        basicSalary:     8_000,
        yearsOfService:  5.0,
        accruedLeaveDays: 0,
        outstandingLoans: 0,
        effectiveDate:    $this->date,
    );

    // gratuity: 8000 × 1.0 × 5 = 40,000
    // severance: 8000 × 1.5 × 5 = 60,000
    expect($r['gratuity'])->toBe(40_000.0);
    expect($r['severance'])->toBe(60_000.0);
});

it('nets outstanding loans from the gross settlement', function () {
    $r = $this->calc->compute(
        exitType:        ExitType::Retirement,
        basicSalary:     5_000,
        yearsOfService:  6.0,
        accruedLeaveDays: 0,
        outstandingLoans: 4_500,
        effectiveDate:    $this->date,
        overrides:        ['pay_paye' => false], // simplify the assertion
    );

    expect($r['gross_settlement'])->toBe(30_000.0); // 5000 × 1.0 × 6
    expect($r['outstanding_loans'])->toBe(4_500.0);
    expect($r['total_deductions'])->toBe(4_500.0);
    expect($r['net_payable'])->toBe(25_500.0);
});

it('respects per-case multiplier overrides', function () {
    $r = $this->calc->compute(
        exitType:        ExitType::Retirement,
        basicSalary:     5_000,
        yearsOfService:  4.0,
        accruedLeaveDays: 0,
        outstandingLoans: 0,
        effectiveDate:    $this->date,
        overrides:        ['gratuity_months_per_year' => 2.0], // generous CBA scheme
    );

    expect($r['gratuity'])->toBe(40_000.0); // 5000 × 2.0 × 4
});

it('rejects negative inputs', function () {
    expect(fn () => $this->calc->compute(
        ExitType::Retirement, basicSalary: 0, yearsOfService: 5,
        accruedLeaveDays: 0, outstandingLoans: 0, effectiveDate: $this->date,
    ))->toThrow(\InvalidArgumentException::class);

    expect(fn () => $this->calc->compute(
        ExitType::Retirement, basicSalary: 5000, yearsOfService: -1,
        accruedLeaveDays: 0, outstandingLoans: 0, effectiveDate: $this->date,
    ))->toThrow(\InvalidArgumentException::class);
});

it('is deterministic — same inputs return identical output', function () {
    $a = $this->calc->compute(ExitType::Retirement, 5000, 7, 5, 1000, $this->date);
    $b = $this->calc->compute(ExitType::Retirement, 5000, 7, 5, 1000, $this->date);
    expect($a)->toEqual($b);
});

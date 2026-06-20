<?php

declare(strict_types=1);

use App\Services\Payroll\Tier3Calculator;
use Database\Seeders\GhanaStatutoryReferenceSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $this->calc = app(Tier3Calculator::class);
    $this->date = '2026-06-30';
});

it('computes a fully-relieved contribution under the cap', function () {
    // basic 5000, rate 5% → elected 250; cap headroom = (16.5%-5%) of 5000 = 575 → fully relieved
    $r = $this->calc->calculate(5000, 0.05, $this->date);
    expect($r['employee'])->toBe(250.0)
        ->and($r['relieved'])->toBe(250.0)
        ->and($r['excess'])->toBe(0.0);
});

it('splits an over-cap contribution into relieved + taxed excess', function () {
    // basic 5000, rate 15% → elected 750; relief headroom = 11.5% of 5000 = 575
    $r = $this->calc->calculate(5000, 0.15, $this->date);
    expect($r['employee'])->toBe(750.0)
        ->and($r['relieved'])->toBe(575.0)   // capped at (16.5-5)% of basic
        ->and($r['excess'])->toBe(175.0);    // 750 - 575, still deducted but taxed
});

it('is a no-op for a zero rate or zero basic', function () {
    expect($this->calc->calculate(5000, 0.0, $this->date))->toBe(['employee' => 0.0, 'relieved' => 0.0, 'excess' => 0.0])
        ->and($this->calc->calculate(0, 0.05, $this->date))->toBe(['employee' => 0.0, 'relieved' => 0.0, 'excess' => 0.0]);
});

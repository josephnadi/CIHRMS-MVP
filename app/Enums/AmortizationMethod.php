<?php

namespace App\Enums;

enum AmortizationMethod: string
{
    case StraightLine     = 'straight_line';     // No interest; principal / n
    case ReducingBalance  = 'reducing_balance';  // PMT formula — standard amortization
    case FlatRate         = 'flat_rate';         // Total interest = P × r × years, then split flat

    public function label(): string
    {
        return match ($this) {
            self::StraightLine    => 'Straight-line (no interest)',
            self::ReducingBalance => 'Reducing balance',
            self::FlatRate        => 'Flat rate',
        };
    }
}

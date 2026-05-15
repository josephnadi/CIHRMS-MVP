<?php

namespace App\Enums;

enum PayrollRunStatus: string
{
    case Draft       = 'draft';
    case Calculating = 'calculating';
    case Calculated  = 'calculated';
    case Approved    = 'approved';
    case Paid        = 'paid';
    case Reversed    = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft       => 'Draft',
            self::Calculating => 'Calculating',
            self::Calculated  => 'Calculated',
            self::Approved    => 'Approved',
            self::Paid        => 'Paid',
            self::Reversed    => 'Reversed',
        };
    }

    public function isMutable(): bool
    {
        return in_array($this, [self::Draft, self::Calculated], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Reversed], true);
    }
}

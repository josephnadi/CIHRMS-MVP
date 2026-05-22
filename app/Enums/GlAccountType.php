<?php

declare(strict_types=1);

namespace App\Enums;

enum GlAccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Equity    = 'equity';
    case Income    = 'income';
    case Expense   = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset     => 'Asset',
            self::Liability => 'Liability',
            self::Equity    => 'Equity',
            self::Income    => 'Income',
            self::Expense   => 'Expense',
        };
    }
}

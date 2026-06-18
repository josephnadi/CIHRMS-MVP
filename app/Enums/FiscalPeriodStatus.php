<?php

declare(strict_types=1);

namespace App\Enums;

enum FiscalPeriodStatus: string
{
    case Open   = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Open   => 'Open',
            self::Closed => 'Closed',
            self::Locked => 'Locked',
        };
    }
}

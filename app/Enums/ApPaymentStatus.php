<?php

declare(strict_types=1);

namespace App\Enums;

enum ApPaymentStatus: string
{
    case Pending   = 'pending';
    case Processed = 'processed';
    case Voided    = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Processed => 'Processed',
            self::Voided    => 'Voided',
        };
    }
}

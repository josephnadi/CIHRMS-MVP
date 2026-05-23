<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentIntentStatus: string
{
    case Created   = 'created';
    case Pending   = 'pending';
    case Success   = 'success';
    case Failed    = 'failed';
    case Abandoned = 'abandoned';
    case Expired   = 'expired';
    case Refunded  = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Created   => 'Created',
            self::Pending   => 'Pending',
            self::Success   => 'Success',
            self::Failed    => 'Failed',
            self::Abandoned => 'Abandoned',
            self::Expired   => 'Expired',
            self::Refunded  => 'Refunded',
        };
    }
}

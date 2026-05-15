<?php

namespace App\Enums;

enum EnrolmentStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
    case Expired   = 'expired';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Active    => 'In progress',
            self::Completed => 'Completed',
            self::Abandoned => 'Abandoned',
            self::Expired   => 'Expired',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending   => '#6b7280',
            self::Active    => '#0051d5',
            self::Completed => '#059669',
            self::Abandoned => '#9ca3af',
            self::Expired   => '#dc2626',
        };
    }
}

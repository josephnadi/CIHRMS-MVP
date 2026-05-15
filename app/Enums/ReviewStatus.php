<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Draft        = 'draft';
    case Submitted    = 'submitted';
    case Acknowledged = 'acknowledged';

    public function label(): string
    {
        return match($this) {
            self::Draft        => 'Draft',
            self::Submitted    => 'Submitted',
            self::Acknowledged => 'Acknowledged',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft        => '#6b7280',
            self::Submitted    => '#0051d5',
            self::Acknowledged => '#059669',
        };
    }
}

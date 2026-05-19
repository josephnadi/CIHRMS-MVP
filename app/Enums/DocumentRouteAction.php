<?php

namespace App\Enums;

enum DocumentRouteAction: string
{
    case Sign        = 'sign';
    case Review      = 'review';
    case Approve     = 'approve';
    case Acknowledge = 'acknowledge';

    public function label(): string
    {
        return match ($this) {
            self::Sign        => 'Sign',
            self::Review      => 'Review',
            self::Approve     => 'Approve',
            self::Acknowledge => 'Acknowledge',
        };
    }
}

<?php

namespace App\Enums;

enum ReviewType: string
{
    case Self       = 'self';
    case Manager    = 'manager';
    case Peer       = 'peer';
    case SkipLevel  = 'skip_level';
    case Upward     = 'upward';

    public function label(): string
    {
        return match($this) {
            self::Self      => 'Self review',
            self::Manager   => 'Manager review',
            self::Peer      => 'Peer review',
            self::SkipLevel => 'Skip-level review',
            self::Upward    => 'Upward review',
        };
    }
}

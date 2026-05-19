<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Open     = 'open';
    case InReview = 'in_review';
    case Closed   = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open     => 'Open',
            self::InReview => 'In Review',
            self::Closed   => 'Closed',
        };
    }
}

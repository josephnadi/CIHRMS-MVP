<?php

namespace App\Enums;

enum DocumentRouteStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Rejected   = 'rejected';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Rejected   => 'Rejected',
            self::Cancelled  => 'Cancelled',
        };
    }
}

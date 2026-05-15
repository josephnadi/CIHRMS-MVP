<?php

namespace App\Enums;

enum GoalStatus: string
{
    case Draft     = 'draft';
    case Active    = 'active';
    case AtRisk    = 'at_risk';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Active    => 'Active',
            self::AtRisk    => 'At Risk',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft     => '#6b7280',
            self::Active    => '#0051d5',
            self::AtRisk    => '#d97706',
            self::Completed => '#059669',
            self::Cancelled => '#9ca3af',
        };
    }
}

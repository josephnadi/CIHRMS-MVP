<?php

namespace App\Enums;

enum GoalCadence: string
{
    case Annual    = 'annual';
    case HalfYear  = 'half_year';
    case Quarterly = 'quarterly';
    case Monthly   = 'monthly';
    case Weekly    = 'weekly';

    public function label(): string
    {
        return match($this) {
            self::Annual    => 'Annual',
            self::HalfYear  => 'Half-yearly',
            self::Quarterly => 'Quarterly',
            self::Monthly   => 'Monthly',
            self::Weekly    => 'Weekly',
        };
    }
}

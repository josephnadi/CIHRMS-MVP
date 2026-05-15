<?php

namespace App\Enums;

enum LeaveType: string
{
    case Annual    = 'annual';
    case Sick      = 'sick';
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case Unpaid    = 'unpaid';
    case Emergency = 'emergency';
    case Study     = 'study';

    public function label(): string
    {
        return match($this) {
            self::Annual    => 'Annual Leave',
            self::Sick      => 'Sick Leave',
            self::Maternity => 'Maternity Leave',
            self::Paternity => 'Paternity Leave',
            self::Unpaid    => 'Unpaid Leave',
            self::Emergency => 'Emergency Leave',
            self::Study     => 'Study Leave',
        };
    }
}

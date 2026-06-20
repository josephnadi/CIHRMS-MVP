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

    /**
     * Default annual entitlement in days (Ghana Labour Act 2003 / common practice).
     * Annual = 15 working days statutory minimum; maternity = 12 weeks (84 calendar
     * days). `null` = no paid entitlement (unpaid leave is not charged to a balance).
     */
    public function defaultEntitlementDays(): ?float
    {
        return match($this) {
            self::Annual    => 15.0,
            self::Sick      => 14.0,
            self::Maternity => 84.0,
            self::Paternity => 7.0,
            self::Emergency => 5.0,
            self::Study     => 10.0,
            self::Unpaid    => null,
        };
    }

    /** Maternity is counted in calendar days (statutory weeks); all others in working days. */
    public function countsWorkingDaysOnly(): bool
    {
        return $this !== self::Maternity;
    }
}

<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present  = 'present';
    case Late     = 'late';
    case HalfDay  = 'half_day';
    case Absent   = 'absent';
    case OnLeave  = 'on_leave';
    case Holiday  = 'holiday';
    case Weekend  = 'weekend';

    public function label(): string
    {
        return match ($this) {
            self::Present  => 'Present',
            self::Late     => 'Late',
            self::HalfDay  => 'Half-day',
            self::Absent   => 'Absent',
            self::OnLeave  => 'On Leave',
            self::Holiday  => 'Public Holiday',
            self::Weekend  => 'Weekend',
        };
    }

    /** Days that count toward the "worked" denominator for payroll proration. */
    public function isWorked(): bool
    {
        return in_array($this, [self::Present, self::Late, self::HalfDay], true);
    }

    public function isExcused(): bool
    {
        return in_array($this, [self::OnLeave, self::Holiday, self::Weekend], true);
    }
}

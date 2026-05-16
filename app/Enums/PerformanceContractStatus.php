<?php

namespace App\Enums;

enum PerformanceContractStatus: string
{
    case Draft       = 'draft';
    case PendingSign = 'pending_signature';
    case Active      = 'active';            // both parties signed; in-cycle
    case Achieved    = 'achieved';          // end-cycle assessment: KPIs met
    case Missed      = 'missed';            // end-cycle assessment: KPIs not met
    case Cancelled   = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft       => 'Draft',
            self::PendingSign => 'Pending Signature',
            self::Active      => 'Active',
            self::Achieved    => 'Achieved',
            self::Missed      => 'Missed',
            self::Cancelled   => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Achieved, self::Missed, self::Cancelled], true);
    }
}

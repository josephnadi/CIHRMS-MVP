<?php

namespace App\Enums;

enum OffboardingStatus: string
{
    case Draft              = 'draft';                 // case opened, clearance items not yet added
    case InProgress         = 'in_progress';           // clearance underway
    case AwaitingSettlement = 'awaiting_settlement';   // clearance complete, settlement to be calculated/approved
    case Settled            = 'settled';               // settlement approved + paid
    case Completed          = 'completed';             // all done, employee status flipped to terminated
    case Cancelled          = 'cancelled';             // case reversed (e.g. resignation withdrawn)

    public function label(): string
    {
        return match ($this) {
            self::Draft              => 'Draft',
            self::InProgress         => 'In Progress',
            self::AwaitingSettlement => 'Awaiting Settlement',
            self::Settled            => 'Settled',
            self::Completed          => 'Completed',
            self::Cancelled          => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}

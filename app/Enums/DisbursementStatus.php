<?php

namespace App\Enums;

enum DisbursementStatus: string
{
    case Pending   = 'pending';      // queued, not yet sent to provider
    case Sent      = 'sent';          // accepted by provider, awaiting settlement
    case Settled   = 'settled';       // money delivered to recipient
    case Failed    = 'failed';
    case Reversed  = 'reversed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Settled, self::Failed, self::Reversed], true);
    }
}

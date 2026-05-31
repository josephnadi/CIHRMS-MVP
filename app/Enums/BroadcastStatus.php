<?php

declare(strict_types=1);

namespace App\Enums;

enum BroadcastStatus: string
{
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Queued    = 'queued';
    case Sending   = 'sending';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Queued    => 'Queued',
            self::Sending   => 'Sending',
            self::Completed => 'Completed',
            self::Failed    => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}

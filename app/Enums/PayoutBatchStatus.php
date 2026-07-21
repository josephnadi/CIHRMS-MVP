<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutBatchStatus: string
{
    case Draft          = 'draft';
    case PendingRelease = 'pending_release';
    case Released       = 'released';
    case Completed      = 'completed';
    case Failed         = 'failed';
    case Cancelled      = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft          => 'Draft',
            self::PendingRelease => 'Pending release',
            self::Released       => 'Released',
            self::Completed      => 'Completed',
            self::Failed         => 'Failed',
            self::Cancelled      => 'Cancelled',
        };
    }
}

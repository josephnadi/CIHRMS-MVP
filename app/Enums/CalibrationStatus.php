<?php

namespace App\Enums;

enum CalibrationStatus: string
{
    case Draft      = 'draft';
    case InProgress = 'in_progress';
    case Locked     = 'locked';       // adjustments frozen, not yet applied to reviews
    case Applied    = 'applied';      // adjusted ratings written back to Review rows
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}

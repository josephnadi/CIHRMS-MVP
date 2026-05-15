<?php

namespace App\Enums;

enum ClearanceItemStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Waived  = 'waived';     // explicitly skipped with reason

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

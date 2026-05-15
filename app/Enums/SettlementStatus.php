<?php

namespace App\Enums;

enum SettlementStatus: string
{
    case Calculated = 'calculated';
    case Approved   = 'approved';
    case Paid       = 'paid';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

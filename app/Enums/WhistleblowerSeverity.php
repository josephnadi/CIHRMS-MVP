<?php

namespace App\Enums;

enum WhistleblowerSeverity: string
{
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

<?php

namespace App\Enums;

enum PositionStatus: string
{
    case Vacant = 'vacant';
    case Filled = 'filled';
    case Frozen = 'frozen';
    case Acting = 'acting';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function canBeFilled(): bool
    {
        return in_array($this, [self::Vacant, self::Acting], true);
    }
}

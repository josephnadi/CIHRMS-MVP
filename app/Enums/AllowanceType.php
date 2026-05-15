<?php

namespace App\Enums;

enum AllowanceType: string
{
    case Housing        = 'housing';
    case Transport      = 'transport';
    case Responsibility = 'responsibility';
    case Risk           = 'risk';
    case Fuel           = 'fuel';
    case Communication  = 'communication';
    case Acting         = 'acting';
    case Entertainment  = 'entertainment';
    case Hardship       = 'hardship';
    case Other          = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isTaxableByDefault(): bool
    {
        // All cash allowances are taxable in Ghana unless explicitly statutory-exempt.
        return true;
    }
}

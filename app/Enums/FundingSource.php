<?php

namespace App\Enums;

enum FundingSource: string
{
    case Gog    = 'gog';    // Government of Ghana consolidated fund
    case Igf    = 'igf';    // Internally generated funds
    case Donor  = 'donor';  // Donor / development partner funded
    case Idf    = 'idf';    // Internal Development Fund
    case Other  = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Gog   => 'Government of Ghana',
            self::Igf   => 'Internally Generated Funds',
            self::Donor => 'Donor / Development Partner',
            self::Idf   => 'Internal Development Fund',
            self::Other => 'Other',
        };
    }
}

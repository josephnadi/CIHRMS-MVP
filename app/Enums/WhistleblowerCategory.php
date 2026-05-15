<?php

namespace App\Enums;

/**
 * Categories of protected disclosure under the Whistleblower Act 2006 (Act 720).
 */
enum WhistleblowerCategory: string
{
    case Corruption        = 'corruption';
    case Fraud             = 'fraud';
    case Harassment        = 'harassment';
    case Discrimination    = 'discrimination';
    case Retaliation       = 'retaliation';
    case Safety            = 'safety';                  // workplace or public safety
    case Environmental     = 'environmental';
    case ConflictOfInterest = 'conflict_of_interest';
    case Mismanagement     = 'mismanagement';            // public-resource mismanagement
    case CriminalOffence   = 'criminal_offence';
    case Other             = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Corruption         => 'Corruption / Bribery',
            self::Fraud              => 'Fraud / Theft',
            self::Harassment         => 'Harassment',
            self::Discrimination     => 'Discrimination',
            self::Retaliation        => 'Retaliation against a whistleblower',
            self::Safety             => 'Workplace or public safety',
            self::Environmental      => 'Environmental harm',
            self::ConflictOfInterest => 'Conflict of interest',
            self::Mismanagement      => 'Public-resource mismanagement',
            self::CriminalOffence    => 'Criminal offence',
            self::Other              => 'Other',
        };
    }
}

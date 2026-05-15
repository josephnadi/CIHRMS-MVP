<?php

namespace App\Enums;

enum ExitType: string
{
    case Resignation       = 'resignation';
    case Retirement        = 'retirement';            // mandatory at 60 or voluntary 55+
    case EndOfContract     = 'end_of_contract';       // fixed-term contract expiry
    case Dismissal         = 'dismissal';             // for cause, summary or notice-based
    case Redundancy        = 'redundancy';            // Act 651 §31
    case MutualSeparation  = 'mutual_separation';
    case Death             = 'death';
    case Abscondment       = 'abscondment';

    public function label(): string
    {
        return match ($this) {
            self::Resignation      => 'Resignation',
            self::Retirement       => 'Retirement',
            self::EndOfContract    => 'End of Contract',
            self::Dismissal        => 'Dismissal',
            self::Redundancy       => 'Redundancy',
            self::MutualSeparation => 'Mutual Separation',
            self::Death            => 'Death',
            self::Abscondment      => 'Abscondment',
        };
    }

    /** Exit types that qualify for full gratuity under Act 651. */
    public function qualifiesForGratuity(): bool
    {
        return in_array($this, [
            self::Retirement,
            self::EndOfContract,
            self::Redundancy,
            self::MutualSeparation,
            self::Death,
        ], true);
    }

    /** Redundancy gets statutory redundancy pay (§31); dismissal-with-cause gets none. */
    public function qualifiesForSeverance(): bool
    {
        return $this === self::Redundancy;
    }
}

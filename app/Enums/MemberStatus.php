<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a CIHRM member's standing. Only `Active` members are
 * candidates for new fee assignments in `BillingRunService`. Other
 * statuses freeze the member: existing AR invoices remain but no new
 * ones are minted for them.
 */
enum MemberStatus: string
{
    case Active    = 'active';
    case Suspended = 'suspended';
    case Lapsed    = 'lapsed';
    case Resigned  = 'resigned';
    case Deceased  = 'deceased';

    public function label(): string
    {
        return match ($this) {
            self::Active    => 'Active',
            self::Suspended => 'Suspended',
            self::Lapsed    => 'Lapsed',
            self::Resigned  => 'Resigned',
            self::Deceased  => 'Deceased',
        };
    }

    /**
     * Eligible for new billings? Only Active members get new invoices on
     * a billing run; everyone else is frozen.
     */
    public function isBillable(): bool
    {
        return $this === self::Active;
    }
}

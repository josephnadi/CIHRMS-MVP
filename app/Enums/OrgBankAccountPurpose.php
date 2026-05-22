<?php

declare(strict_types=1);

namespace App\Enums;

enum OrgBankAccountPurpose: string
{
    case Operating       = 'operating';
    case Payroll         = 'payroll';
    case StatutoryEscrow = 'statutory_escrow';
    case Receipts        = 'receipts';
    case Reserve         = 'reserve';

    public function label(): string
    {
        return match ($this) {
            self::Operating       => 'Operating',
            self::Payroll         => 'Payroll',
            self::StatutoryEscrow => 'Statutory Escrow',
            self::Receipts        => 'Receipts',
            self::Reserve         => 'Reserve',
        };
    }
}

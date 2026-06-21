<?php

namespace App\Enums;

enum StatutoryReturnKind: string
{
    case Paye         = 'paye';
    case SsnitTier1   = 'ssnit_tier1';
    case Tier2Trustee = 'tier2_trustee';
    case Tier3        = 'tier3';
    case NhiaSplit    = 'nhia_split';
    case BankFile     = 'bank_file';

    public function label(): string
    {
        return match ($this) {
            self::Paye         => 'GRA P.A.Y.E. Return',
            self::SsnitTier1   => 'SSNIT Tier-1 Contribution Schedule',
            self::Tier2Trustee => 'Tier-2 Trustee Schedule',
            self::Tier3        => 'Tier-3 Voluntary Pension Schedule',
            self::NhiaSplit    => 'NHIA Allocation Statement',
            self::BankFile     => 'Bank Disbursement File',
        };
    }

    public function fileExtension(): string
    {
        return match ($this) {
            self::BankFile => 'txt',
            default        => 'csv',
        };
    }

    /**
     * The payroll liability account slug this return clears when filed, or null
     * when the return is informational and carries no accrued liability of its
     * own. NHIA is funded out of the SSNIT contribution (no separate payable),
     * and the bank file is the net-pay disbursement instruction, not a return.
     */
    public function liabilitySlug(): ?string
    {
        return match ($this) {
            self::Paye         => 'payroll.paye_payable',
            self::SsnitTier1   => 'payroll.ssnit_payable',
            self::Tier2Trustee => 'payroll.tier2_payable',
            self::Tier3        => 'payroll.tier3_payable',
            self::NhiaSplit, self::BankFile => null,
        };
    }
}

<?php

namespace App\Enums;

/**
 * Disbursement rails the payroll engine can route a net-pay line through.
 * Selected per-employee on the Employee row; the BatchDisbursementService
 * groups payroll lines by channel and hands each group to its adapter.
 */
enum DisbursementChannel: string
{
    case GhipssAch     = 'ghipss_ach';        // Traditional bank file (default)
    case MtnMomo       = 'mtn_momo';           // MTN Mobile Money
    case VodafoneCash  = 'vodafone_cash';      // Vodafone Cash
    case AirtelTigo    = 'airtel_tigo';        // AirtelTigo Money
    case HubtelBank    = 'hubtel_bank';         // Bank transfer via Hubtel payout API
    case Cash          = 'cash';               // Petty / casual employees
    case Cheque        = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::GhipssAch    => 'GhIPSS Bank Transfer',
            self::MtnMomo      => 'MTN MoMo',
            self::VodafoneCash => 'Vodafone Cash',
            self::AirtelTigo   => 'AirtelTigo Money',
            self::HubtelBank   => 'Hubtel Bank Transfer',
            self::Cash         => 'Cash',
            self::Cheque       => 'Cheque',
        };
    }

    /** Whether this channel is subject to the 1.5% E-Levy on B2C transfers. */
    public function attractsELevy(): bool
    {
        return in_array($this, [self::MtnMomo, self::VodafoneCash, self::AirtelTigo], true);
    }

    /** Whether this channel can be auto-disbursed via a provider API. */
    public function isAutomated(): bool
    {
        return in_array($this, [self::GhipssAch, self::MtnMomo, self::VodafoneCash, self::AirtelTigo], true);
    }
}

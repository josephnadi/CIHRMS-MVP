<?php

namespace App\Enums;

enum LoanStatus: string
{
    case Draft           = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Disbursed       = 'disbursed';
    case Repaying        = 'repaying';
    case PaidOff         = 'paid_off';
    case Defaulted       = 'defaulted';
    case WrittenOff      = 'written_off';
    case Cancelled       = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::Rejected        => 'Rejected',
            self::Disbursed       => 'Disbursed',
            self::Repaying        => 'Repaying',
            self::PaidOff         => 'Paid Off',
            self::Defaulted       => 'Defaulted',
            self::WrittenOff      => 'Written Off',
            self::Cancelled       => 'Cancelled',
        };
    }

    /** Loans in these states feed deductions into payroll runs. */
    public function isActiveForRepayment(): bool
    {
        return in_array($this, [self::Disbursed, self::Repaying], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::PaidOff, self::WrittenOff, self::Cancelled, self::Rejected], true);
    }
}

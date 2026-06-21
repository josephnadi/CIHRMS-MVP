<?php

declare(strict_types=1);

namespace App\Enums;

enum JournalSourceType: string
{
    case Manual         = 'manual';
    case VendorInvoice  = 'vendor_invoice';
    case ApPayment      = 'ap_payment';
    case ArInvoice      = 'ar_invoice';
    case ArReceipt      = 'ar_receipt';
    case BankAdjustment   = 'bank_adjustment';
    case Payroll          = 'payroll';
    case Disbursement     = 'disbursement';
    case LoanDisbursement = 'loan_disbursement';
    case LoanRepayment    = 'loan_repayment';
    case MemberFee        = 'member_fee';
    case FinalSettlement  = 'final_settlement';
    case StatutoryRemittance = 'statutory_remittance';

    public function label(): string
    {
        return match ($this) {
            self::Manual         => 'Manual',
            self::VendorInvoice  => 'Vendor Invoice',
            self::ApPayment      => 'AP Payment',
            self::ArInvoice      => 'AR Invoice',
            self::ArReceipt      => 'AR Receipt',
            self::BankAdjustment => 'Bank Adjustment',
            self::Payroll          => 'Payroll',
            self::Disbursement     => 'Disbursement',
            self::LoanDisbursement => 'Loan Disbursement',
            self::LoanRepayment    => 'Loan Repayment',
            self::MemberFee        => 'Member Fee',
            self::FinalSettlement   => 'Final Settlement',
            self::StatutoryRemittance => 'Statutory Remittance',
        };
    }
}

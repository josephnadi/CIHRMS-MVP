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
    case BankAdjustment = 'bank_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Manual         => 'Manual',
            self::VendorInvoice  => 'Vendor Invoice',
            self::ApPayment      => 'AP Payment',
            self::ArInvoice      => 'AR Invoice',
            self::ArReceipt      => 'AR Receipt',
            self::BankAdjustment => 'Bank Adjustment',
        };
    }
}

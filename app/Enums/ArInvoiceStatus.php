<?php

declare(strict_types=1);

namespace App\Enums;

enum ArInvoiceStatus: string
{
    case Draft            = 'draft';
    case PendingApproval  = 'pending_approval';
    case Approved         = 'approved';
    case PartiallyPaid    = 'partially_paid';
    case Paid             = 'paid';
    case Cancelled        = 'cancelled';
    case WrittenOff       = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::PartiallyPaid   => 'Partially Paid',
            self::Paid            => 'Paid',
            self::Cancelled       => 'Cancelled',
            self::WrittenOff      => 'Written Off',
        };
    }
}

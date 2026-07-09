<?php

declare(strict_types=1);

namespace App\Enums;

enum IncomingInvoiceStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Vetted    = 'vetted';
    case Approved  = 'approved';
    case Posted    = 'posted';
    case Returned  = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Submitted => 'Submitted for Vetting',
            self::Vetted    => 'Vetted — Pending CEO',
            self::Approved  => 'Approved',
            self::Posted    => 'Posted to GL',
            self::Returned  => 'Returned',
        };
    }
}

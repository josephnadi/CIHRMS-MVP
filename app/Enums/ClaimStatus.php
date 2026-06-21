<?php

declare(strict_types=1);

namespace App\Enums;

enum ClaimStatus: string
{
    case Submitted = 'submitted';
    case Reviewing = 'reviewing';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Paid      = 'paid';
    case Withdrawn = 'withdrawn';

    public function isDecided(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Paid, self::Withdrawn], true);
    }
}

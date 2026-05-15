<?php

namespace App\Enums;

enum IdentityVerificationStatus: string
{
    case Pending  = 'pending';
    case Verified = 'verified';
    case Failed   = 'failed';
    case Expired  = 'expired';
    case Disputed = 'disputed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isUsable(): bool
    {
        return $this === self::Verified;
    }
}

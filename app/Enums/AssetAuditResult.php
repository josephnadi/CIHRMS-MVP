<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetAuditResult: string
{
    case Pending       = 'pending';
    case Present       = 'present';
    case Missing       = 'missing';
    case WrongLocation = 'wrong_location';
    case WrongHolder   = 'wrong_holder';
    case Damaged       = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Pending       => 'Not counted',
            self::Present       => 'Present',
            self::Missing       => 'Missing',
            self::WrongLocation => 'Wrong location',
            self::WrongHolder   => 'Wrong holder',
            self::Damaged       => 'Damaged',
        };
    }

    public function isDiscrepancy(): bool
    {
        return ! in_array($this, [self::Pending, self::Present], true);
    }
}

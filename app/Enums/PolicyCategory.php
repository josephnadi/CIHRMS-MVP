<?php

declare(strict_types=1);

namespace App\Enums;

enum PolicyCategory: string
{
    case Hr         = 'hr';
    case Finance    = 'finance';
    case It         = 'it';
    case Compliance = 'compliance';
    case Safety     = 'safety';
    case Conduct    = 'conduct';
    case Other      = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hr         => 'HR',
            self::Finance    => 'Finance',
            self::It         => 'IT',
            self::Compliance => 'Compliance',
            self::Safety     => 'Safety',
            self::Conduct    => 'Conduct',
            self::Other      => 'Other',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetCategory: string
{
    case Laptop    = 'laptop';
    case Monitor   = 'monitor';
    case Phone     = 'phone';
    case Vehicle   = 'vehicle';
    case Furniture = 'furniture';
    case Other     = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Laptop    => 'Laptop',
            self::Monitor   => 'Monitor',
            self::Phone     => 'Phone',
            self::Vehicle   => 'Vehicle',
            self::Furniture => 'Furniture',
            self::Other     => 'Other',
        };
    }
}

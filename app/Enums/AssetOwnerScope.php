<?php

namespace App\Enums;

enum AssetOwnerScope: string
{
    case Personal     = 'personal';
    case Department   = 'department';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Personal     => 'Personal',
            self::Department   => 'Department',
            self::Organization => 'Organization',
        };
    }
}

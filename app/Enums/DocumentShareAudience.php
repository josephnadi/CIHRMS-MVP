<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentShareAudience: string
{
    case User         = 'user';
    case Department   = 'department';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::User         => 'Individual user',
            self::Department   => 'Department',
            self::Organization => 'Entire organization',
        };
    }
}

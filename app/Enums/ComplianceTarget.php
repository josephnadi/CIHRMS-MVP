<?php

declare(strict_types=1);

namespace App\Enums;

enum ComplianceTarget: string
{
    case AllStaff   = 'all_staff';
    case Role       = 'role';
    case Department = 'department';

    public function label(): string
    {
        return match ($this) {
            self::AllStaff   => 'All staff',
            self::Role       => 'Role',
            self::Department => 'Department',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum DependantRelationship: string
{
    case Spouse = 'spouse';
    case Child  = 'child';
    case Parent = 'parent';
    case Other  = 'other';
}

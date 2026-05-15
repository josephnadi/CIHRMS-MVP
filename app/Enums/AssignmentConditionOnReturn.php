<?php

declare(strict_types=1);

namespace App\Enums;

enum AssignmentConditionOnReturn: string
{
    case Good    = 'good';
    case Fair    = 'fair';
    case Poor    = 'poor';
    case Damaged = 'damaged';
}

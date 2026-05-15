<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetStatus: string
{
    case InStock     = 'in_stock';
    case Assigned    = 'assigned';
    case Maintenance = 'maintenance';
    case Retired     = 'retired';
    case Lost        = 'lost';
}

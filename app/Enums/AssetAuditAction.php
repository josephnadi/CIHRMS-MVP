<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetAuditAction: string
{
    case None              = 'none';
    case MarkedLost        = 'marked_lost';
    case Relocated         = 'relocated';
    case MaintenanceLogged = 'maintenance_logged';
    case Flagged           = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::None              => 'None',
            self::MarkedLost        => 'Marked lost',
            self::Relocated         => 'Relocated',
            self::MaintenanceLogged => 'Maintenance logged',
            self::Flagged           => 'Flagged for reassignment',
        };
    }
}

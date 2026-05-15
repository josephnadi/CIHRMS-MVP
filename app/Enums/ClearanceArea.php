<?php

namespace App\Enums;

enum ClearanceArea: string
{
    case ItAssets       = 'it_assets';         // laptop, sim, accounts, ID cards
    case Finance        = 'finance';           // outstanding loans, advances, imprest
    case HrRecords      = 'hr_records';        // file return, exit interview, NDA reaffirmation
    case Library        = 'library';
    case Stores         = 'stores';            // uniforms, tools, vehicles
    case Security       = 'security';          // gate pass, parking
    case DeptHandover   = 'dept_handover';     // departmental handover note + knowledge transfer
    case Pension        = 'pension';           // SSNIT discharge form, Tier-2 trustee notification
    case Other          = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ItAssets     => 'IT & Assets',
            self::Finance      => 'Finance',
            self::HrRecords    => 'HR Records',
            self::Library      => 'Library',
            self::Stores       => 'Stores',
            self::Security     => 'Security & Access',
            self::DeptHandover => 'Departmental Handover',
            self::Pension      => 'Pension Discharge',
            self::Other        => 'Other',
        };
    }
}

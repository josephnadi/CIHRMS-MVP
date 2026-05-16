<?php

namespace App\Enums;

enum AnnouncementSeverity: string
{
    case Info      = 'info';
    case Important = 'important';
    case Urgent    = 'urgent';

    public function label(): string
    {
        return match($this) {
            self::Info      => 'Info',
            self::Important => 'Important',
            self::Urgent    => 'Urgent',
        };
    }
}

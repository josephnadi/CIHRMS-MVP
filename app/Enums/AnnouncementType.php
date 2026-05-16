<?php

namespace App\Enums;

enum AnnouncementType: string
{
    case Notice    = 'notice';
    case Event     = 'event';
    case Birthday  = 'birthday';
    case Task      = 'task';
    case System    = 'system';

    public function label(): string
    {
        return match($this) {
            self::Notice   => 'Notice',
            self::Event    => 'Event',
            self::Birthday => 'Birthday',
            self::Task     => 'Task',
            self::System   => 'System',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::Notice   => 'campaign',
            self::Event    => 'event',
            self::Birthday => 'cake',
            self::Task     => 'task_alt',
            self::System   => 'info',
        };
    }
}

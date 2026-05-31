<?php

declare(strict_types=1);

namespace App\Enums;

enum BroadcastChannel: string
{
    case Sms  = 'sms';
    case Mail = 'mail';
}

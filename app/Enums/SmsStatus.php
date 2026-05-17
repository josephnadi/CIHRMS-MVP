<?php

namespace App\Enums;

enum SmsStatus: string
{
    case Queued    = 'queued';
    case Sent      = 'sent';        // accepted by provider
    case Delivered = 'delivered';   // confirmed delivered to handset
    case Failed    = 'failed';
    case Expired   = 'expired';     // queued > N minutes without dispatch

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Expired], true);
    }
}

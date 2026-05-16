<?php

namespace App\Enums;

enum PipStatus: string
{
    case Open             = 'open';
    case InProgress       = 'in_progress';
    case Succeeded        = 'succeeded';        // employee met targets
    case Extended         = 'extended';         // PIP extended for further observation
    case FailedDemoted    = 'failed_demoted';
    case FailedTerminated = 'failed_terminated';
    case Cancelled        = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded, self::FailedDemoted, self::FailedTerminated, self::Cancelled,
        ], true);
    }
}

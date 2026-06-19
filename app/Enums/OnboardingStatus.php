<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingStatus: string
{
    case Draft      = 'draft';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }
}

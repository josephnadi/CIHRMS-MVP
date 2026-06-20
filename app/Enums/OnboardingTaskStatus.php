<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingTaskStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Skipped   = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft     = 'draft';
    case InReview  = 'in_review';
    case Completed = 'completed';
    case Rejected  = 'rejected';
    case Withdrawn = 'withdrawn';
    case Archived  = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::InReview  => 'In Review',
            self::Completed => 'Completed',
            self::Rejected  => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::Archived  => 'Archived',
        };
    }
}

<?php

namespace App\Enums;

enum IncidentCategory: string
{
    case Grievance             = 'grievance';
    case ImprovementSuggestion = 'improvement';
    case WorkplaceSafety       = 'safety';
    case Other                 = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Grievance             => 'Grievance',
            self::ImprovementSuggestion => 'Improvement Suggestion',
            self::WorkplaceSafety       => 'Workplace Safety',
            self::Other                 => 'Other',
        };
    }
}

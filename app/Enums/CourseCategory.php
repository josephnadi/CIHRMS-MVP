<?php

namespace App\Enums;

enum CourseCategory: string
{
    case Technical   = 'technical';
    case Leadership  = 'leadership';
    case Compliance  = 'compliance';
    case Wellness    = 'wellness';
    case Onboarding  = 'onboarding';
    case Soft        = 'soft_skills';
    case Other       = 'other';

    public function label(): string
    {
        return match($this) {
            self::Technical  => 'Technical',
            self::Leadership => 'Leadership',
            self::Compliance => 'Compliance',
            self::Wellness   => 'Wellness',
            self::Onboarding => 'Onboarding',
            self::Soft       => 'Soft skills',
            self::Other      => 'Other',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Technical  => '#0051d5',
            self::Leadership => '#7c3aed',
            self::Compliance => '#dc2626',
            self::Wellness   => '#059669',
            self::Onboarding => '#d97706',
            self::Soft       => '#0891b2',
            self::Other      => '#6b7280',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingArea: string
{
    case ItProvisioning       = 'it_provisioning';
    case HrOrientation        = 'hr_orientation';
    case PolicyAcknowledgement = 'policy_acknowledgement';
    case Learning             = 'learning';
    case Mentorship           = 'mentorship';
    case DeptIntroduction     = 'dept_introduction';
    case Other                = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ItProvisioning        => 'IT Provisioning',
            self::HrOrientation         => 'HR Orientation',
            self::PolicyAcknowledgement => 'Policy Acknowledgement',
            self::Learning              => 'Learning',
            self::Mentorship            => 'Mentorship',
            self::DeptIntroduction      => 'Department Introduction',
            self::Other                 => 'Other',
        };
    }
}

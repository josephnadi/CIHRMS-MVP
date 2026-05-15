<?php

declare(strict_types=1);

namespace App\Enums;

enum BenefitType: string
{
    case HealthInsurance = 'health_insurance';
    case ProvidentFund   = 'provident_fund';
    case LifeInsurance   = 'life_insurance';
    case Dental          = 'dental';
    case Vision          = 'vision';
    case Wellness        = 'wellness';
    case Other           = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HealthInsurance => 'Health Insurance',
            self::ProvidentFund   => 'Provident Fund',
            self::LifeInsurance   => 'Life Insurance',
            self::Dental          => 'Dental',
            self::Vision          => 'Vision',
            self::Wellness        => 'Wellness',
            self::Other           => 'Other',
        };
    }
}

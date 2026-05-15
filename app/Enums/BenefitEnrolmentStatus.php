<?php

declare(strict_types=1);

namespace App\Enums;

enum BenefitEnrolmentStatus: string
{
    case Active     = 'active';
    case Suspended  = 'suspended';
    case Terminated = 'terminated';
}

<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\BenefitEnrolment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class BenefitEnroled
{
    use Dispatchable;

    public function __construct(
        public readonly BenefitEnrolment $enrolment,
        public readonly ?User $actor = null,
    ) {}
}

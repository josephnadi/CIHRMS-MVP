<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\BenefitPlan;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class BenefitPlanCreated
{
    use Dispatchable;

    public function __construct(
        public readonly BenefitPlan $plan,
        public readonly ?User $actor = null,
    ) {}
}

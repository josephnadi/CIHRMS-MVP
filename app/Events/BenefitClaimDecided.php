<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\BenefitClaim;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class BenefitClaimDecided
{
    use Dispatchable;

    public function __construct(
        public readonly BenefitClaim $claim,
        public readonly ?User $actor = null,
    ) {}
}

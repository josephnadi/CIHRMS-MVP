<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PolicyDrafted
{
    use Dispatchable;

    public function __construct(
        public readonly Policy $policy,
        public readonly ?User $actor = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PolicyVersion;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PolicyVersionAdded
{
    use Dispatchable;

    public function __construct(
        public readonly PolicyVersion $version,
        public readonly ?User $actor = null,
    ) {}
}

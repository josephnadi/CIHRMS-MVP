<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Dependant;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class DependantAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Dependant $dependant,
        public readonly ?User $actor = null,
    ) {}
}

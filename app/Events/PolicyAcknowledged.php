<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PolicyAcknowledgement;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PolicyAcknowledged
{
    use Dispatchable;

    public function __construct(
        public readonly PolicyAcknowledgement $acknowledgement,
        public readonly ?User $actor = null,
    ) {}
}

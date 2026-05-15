<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class AssetMarkedLost
{
    use Dispatchable;

    public function __construct(
        public readonly Asset $asset,
        public readonly ?User $actor = null,
    ) {}
}

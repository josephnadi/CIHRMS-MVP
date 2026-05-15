<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class AssetAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly AssetAssignment $assignment,
        public readonly ?User $actor = null,
    ) {}
}

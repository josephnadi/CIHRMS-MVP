<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class CertificationExpiring
{
    use Dispatchable;

    public function __construct(
        public readonly Certification $certification,
        public readonly ?User $actor = null,
    ) {}
}

<?php

namespace App\Events;

use App\Models\SsoIdentityProvider;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SsoLoginCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly SsoIdentityProvider $provider,
        public readonly bool $wasJustProvisioned,
    ) {}
}

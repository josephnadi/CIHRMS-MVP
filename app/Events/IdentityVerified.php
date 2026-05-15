<?php

namespace App\Events;

use App\Models\IdentityVerification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IdentityVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly IdentityVerification $verification) {}
}

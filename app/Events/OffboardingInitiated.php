<?php

namespace App\Events;

use App\Models\OffboardingCase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OffboardingInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly OffboardingCase $case) {}
}

<?php

namespace App\Events;

use App\Models\PayrollRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayrollRunReversed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PayrollRun $run, public readonly string $reason) {}
}

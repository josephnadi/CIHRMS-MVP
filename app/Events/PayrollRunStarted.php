<?php

namespace App\Events;

use App\Models\PayrollRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayrollRunStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PayrollRun $run) {}
}

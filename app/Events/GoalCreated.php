<?php

namespace App\Events;

use App\Models\Goal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GoalCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Goal $goal) {}
}

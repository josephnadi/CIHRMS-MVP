<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeaveRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly ?User $actor,
    ) {}
}

<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmployeeCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Employee $employee,
        public readonly ?User $actor,
    ) {}
}

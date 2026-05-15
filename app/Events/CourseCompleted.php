<?php

namespace App\Events;

use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CourseCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Enrolment $enrolment,
        public readonly ?User $actor = null,
    ) {}
}

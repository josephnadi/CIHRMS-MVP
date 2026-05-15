<?php

namespace App\Events;

use App\Models\Applicant;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApplicantHired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Applicant $applicant,
        public readonly ?Employee $employee = null,
        public readonly ?User $actor = null,
    ) {}
}

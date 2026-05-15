<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AttendanceCorrection;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class AttendanceCorrectionDecided
{
    use Dispatchable;

    public function __construct(
        public readonly AttendanceCorrection $correction,
        public readonly ?User $actor = null,
    ) {}
}

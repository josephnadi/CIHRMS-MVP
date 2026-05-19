<?php

namespace App\Events\Incident;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReportClosed
{
    use Dispatchable;

    public function __construct(
        public IncidentReport $report,
        public User $actor,
    ) {}
}

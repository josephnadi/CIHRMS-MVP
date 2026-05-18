<?php

namespace App\Events\Incident;

use App\Models\IncidentReportMessage;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentMessagePosted
{
    use Dispatchable;

    public function __construct(public IncidentReportMessage $message) {}
}

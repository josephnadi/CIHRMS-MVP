<?php

namespace App\Events;

use App\Models\WhistleblowerReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhistleblowerReportSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly WhistleblowerReport $report) {}
}

<?php

namespace App\Listeners;

use App\Events\PayrollRunApproved;
use App\Services\Disbursement\BatchDisbursementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Side-effect of `PayrollRunApproved`: materialise Disbursement rows for every
 * payroll line. The actual provider push is a separate explicit action
 * triggered by Finance, since it moves real money.
 */
class MaterialiseDisbursements implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function viaQueue(): string
    {
        return 'payroll';
    }

    public function __construct(private readonly BatchDisbursementService $batch) {}

    public function handle(PayrollRunApproved $event): void
    {
        $this->batch->materialise($event->run);
    }
}

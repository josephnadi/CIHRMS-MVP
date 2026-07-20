<?php

namespace App\Listeners;

use App\Events\PayrollRunApproved;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\PayoutBatchService;
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

    public function __construct(
        private readonly BatchDisbursementService $batch,
        private readonly PayoutBatchService $batches,
    ) {}

    public function handle(PayrollRunApproved $event): void
    {
        $this->batch->materialise($event->run);

        // Wrap the newly materialised Pending rows into a PendingRelease batch so
        // Finance can review/release them — approval itself never sends money.
        // Maker is the run's approver (falls back to its creator for safety);
        // must be a real user id so the maker-checker guard at release holds.
        $makerId = (int) ($event->run->approved_by ?? $event->run->created_by ?? 0);
        if ($makerId > 0) {
            $this->batches->createForPayrollRun($event->run, $makerId);
        }
    }
}

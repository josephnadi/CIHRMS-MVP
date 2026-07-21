<?php

namespace App\Listeners;

use App\Events\PayrollRunApproved;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\PayoutBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

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
        $created = $this->batch->materialise($event->run);

        // Wrap the newly materialised Pending rows into a PendingRelease batch so
        // Finance can review/release them — approval itself never sends money.
        // Maker is the run's approver (falls back to its creator for safety);
        // must be a real user id so the maker-checker guard at release holds.
        //
        // Only wrap when this fire actually materialised rows: a retried/queued
        // re-fire (tries=3) or a lineless run materialises nothing, and wrapping
        // then would litter the release queue with empty (total 0) batches.
        $makerId = (int) ($event->run->approved_by ?? $event->run->created_by ?? 0);
        if ($created > 0 && $makerId > 0) {
            $this->batches->createForPayrollRun($event->run, $makerId);
        } elseif ($created > 0 && $makerId === 0) {
            // Both approver and creator are null/0 — the newly materialised rows
            // were NOT wrapped into a PayoutBatch (createForPayrollRun requires a
            // real user id for the maker-checker chain), so they sit Pending with
            // no batch. That's silent and easy to miss operationally: flag it.
            Log::warning('MaterialiseDisbursements: run approved with no maker id; disbursements left unbatched', [
                'payroll_run_id' => $event->run->id,
                'created'        => $created,
            ]);
        }
    }
}

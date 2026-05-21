<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PayrollRunPaid;
use App\Services\Payroll\Gifmis\GifmisJournalExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Side-effect of `PayrollRunPaid`: build the GIFMIS journal-voucher CSV and
 * persist it to the configured disk so the state accountant can upload it
 * straight to GIFMIS without re-keying.
 *
 * Gated on `payroll.gifmis.auto_mint_on_paid` (off by default) so the first
 * few runs through a new MDA still get a manual review step before any
 * sub-ledger import. Production MDAs that have validated their GL-code map
 * flip the flag to true and the journal is minted on every payment.
 *
 * If the journal won't balance, the exporter throws — we log a critical
 * error rather than crashing the payment flow, because the run is already
 * Paid and rolling that back is much worse than a missing JV file.
 */
class MintGifmisJournal implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function viaQueue(): string
    {
        return 'payroll';
    }

    public function __construct(private readonly GifmisJournalExporter $exporter) {}

    public function handle(PayrollRunPaid $event): void
    {
        if (! config('payroll.gifmis.auto_mint_on_paid', false)) {
            return;
        }

        try {
            $path = $this->exporter->build($event->run);
            Log::info("GIFMIS journal auto-minted for PayrollRun#{$event->run->id}", [
                'path' => $path,
            ]);
        } catch (\RuntimeException $e) {
            Log::critical("GIFMIS journal auto-mint failed for PayrollRun#{$event->run->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

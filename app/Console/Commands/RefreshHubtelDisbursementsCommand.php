<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Services\Disbursement\BatchDisbursementService;
use Illuminate\Console\Command;

class RefreshHubtelDisbursementsCommand extends Command
{
    protected $signature = 'payouts:refresh-hubtel {--minutes=15}';
    protected $description = 'Poll Hubtel for the status of Sent bank disbursements the webhook has not settled yet.';

    public function handle(BatchDisbursementService $batch): int
    {
        $stale = Disbursement::query()
            ->where('channel', DisbursementChannel::HubtelBank->value)
            ->where('status', DisbursementStatus::Sent->value)
            ->where('sent_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        $touched = 0;
        foreach ($stale as $d) {
            if ($batch->reconcileOne($d)) {
                $touched++;
            }
        }

        $this->info("Refreshed {$touched} Hubtel disbursement(s).");
        return self::SUCCESS;
    }
}

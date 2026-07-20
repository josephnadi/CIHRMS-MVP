<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Disbursement;
use App\Models\HubtelWebhookEvent;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Support\Facades\Log;

class HubtelWebhookProcessor
{
    public function __construct(private readonly BatchDisbursementService $batch) {}

    public function process(HubtelWebhookEvent $event): void
    {
        if ($event->processed_at !== null) {
            return;
        }

        $data      = data_get($event->payload, 'Data', []);
        $clientRef = (string) ($data['ClientReference'] ?? '');
        $txId      = (string) ($data['TransactionId'] ?? '');
        $status    = strtolower((string) ($data['Status'] ?? ''));

        // ClientReference is "PAYOUT-{disbursement_id}"
        $disbursementId = (int) str_replace('PAYOUT-', '', $clientRef);
        $d = Disbursement::find($disbursementId)
            ?? Disbursement::where('provider_reference', $txId)->first();

        if (! $d) {
            Log::info('Hubtel webhook: no matching disbursement', ['ref' => $clientRef, 'tx' => $txId]);
            $event->update(['processed_at' => now()]);
            return;
        }

        $result = match ($status) {
            'paid', 'success', 'successful' => DisbursementResult::settled($txId ?: (string) $d->provider_reference, (array) $event->payload),
            'failed', 'declined', 'reversed' => DisbursementResult::failed("Hubtel reported {$status}", (array) $event->payload),
            default => null,
        };

        if ($result !== null) {
            $this->batch->applyResult($d, $result);
        }

        $event->update(['processed_at' => now()]);
    }
}

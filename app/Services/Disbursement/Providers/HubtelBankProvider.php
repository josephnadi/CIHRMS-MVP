<?php

declare(strict_types=1);

namespace App\Services\Disbursement\Providers;

use App\Enums\DisbursementChannel;
use App\Models\Disbursement;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Support\Facades\Http;

/**
 * Hubtel payout (bank transfer) provider.
 *
 * Hubtel exposes a synchronous "send" that accepts the transfer and returns a
 * TransactionId; final disposition arrives via the configured callback
 * (webhook). Idempotency-Key = `HUBTEL-{disbursement_id}` so retries are safe.
 */
class HubtelBankProvider implements DisbursementProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $merchantAccount,
        private readonly string $callbackUrl,
        private readonly int    $timeoutSeconds = 15,
    ) {}

    public function channel(): string
    {
        return DisbursementChannel::HubtelBank->value;
    }

    public function send(Disbursement $d): DisbursementResult
    {
        if (empty($d->beneficiary_account)) {
            return DisbursementResult::failed('Hubtel: beneficiary bank account is missing.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->withHeaders(['Idempotency-Key' => "HUBTEL-{$d->id}"])
                ->post("{$this->baseUrl}/transactions/{$this->merchantAccount}/send", [
                    'RecipientName'       => (string) $d->beneficiary_name,
                    'RecipientBankAccount'=> (string) $d->beneficiary_account,
                    'Amount'              => (float) $d->net_to_recipient,
                    'Description'         => "CIHRM payout #{$d->id}",
                    'ClientReference'     => "PAYOUT-{$d->id}",
                    'CallbackUrl'         => $this->callbackUrl,
                ]);
        } catch (\Throwable $e) {
            return DisbursementResult::failed("Hubtel transport error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            $txId = (string) ($response->json('Data.TransactionId') ?? '');
            if ($txId !== '') {
                return DisbursementResult::sent($txId, $response->json() ?? []);
            }
        }

        return DisbursementResult::failed(
            "Hubtel HTTP {$response->status()}: " . substr($response->body(), 0, 200),
            ['body' => $response->json() ?? $response->body()],
        );
    }

    public function refreshStatus(Disbursement $d): DisbursementResult
    {
        if (! $d->provider_reference) {
            return DisbursementResult::failed('No provider reference to query.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->get("{$this->baseUrl}/transactions/{$this->merchantAccount}/status/{$d->provider_reference}");
        } catch (\Throwable $e) {
            return DisbursementResult::failed("Hubtel status poll error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return DisbursementResult::failed("Hubtel status HTTP {$response->status()}");
        }

        $status = (string) ($response->json('Data.Status') ?? '');

        return match (strtolower($status)) {
            'paid', 'success', 'successful' => DisbursementResult::settled((string) $d->provider_reference, $response->json() ?? []),
            'failed', 'declined', 'reversed' => DisbursementResult::failed("Hubtel reported {$status}", $response->json() ?? []),
            default => DisbursementResult::sent((string) $d->provider_reference, $response->json() ?? []),
        };
    }
}

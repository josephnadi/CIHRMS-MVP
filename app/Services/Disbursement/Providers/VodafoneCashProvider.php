<?php

namespace App\Services\Disbursement\Providers;

use App\Enums\DisbursementChannel;
use App\Models\Disbursement;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Support\Facades\Http;

/**
 * Vodafone Cash B2C disbursement adapter.
 *
 * Auth model is API-key + signing-secret (HMAC of payload + timestamp).
 * Different from MTN's OAuth2 flow but the same outer pattern.
 */
class VodafoneCashProvider implements DisbursementProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $signingSecret,
        private readonly int    $timeoutSeconds = 15,
    ) {}

    public function channel(): string
    {
        return DisbursementChannel::VodafoneCash->value;
    }

    public function send(Disbursement $d): DisbursementResult
    {
        $body = [
            'reference' => "CIHRMS-{$d->payroll_run_id}-{$d->id}",
            'msisdn'    => $this->normalisedMsisdn($d->beneficiary_account),
            'amount'    => (string) $d->net_to_recipient,
            'currency'  => 'GHS',
            'narration' => "Salary — {$d->beneficiary_name}",
            'timestamp' => now()->toIso8601String(),
        ];
        $body['signature'] = hash_hmac('sha256', json_encode($body), $this->signingSecret);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['X-API-Key' => $this->apiKey])
                ->post("{$this->baseUrl}/b2c/send", $body);
        } catch (\Throwable $e) {
            return DisbursementResult::failed("Vodafone Cash transport error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return DisbursementResult::failed("Vodafone Cash HTTP {$response->status()}", ['body' => $response->body()]);
        }

        $data = $response->json() ?? [];
        $ref  = (string) ($data['transactionId'] ?? '');
        return $ref !== ''
            ? DisbursementResult::sent($ref, $data)
            : DisbursementResult::failed('No transactionId in response', $data);
    }

    public function refreshStatus(Disbursement $d): DisbursementResult
    {
        if (! $d->provider_reference) {
            return DisbursementResult::failed('No provider reference to query.');
        }
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['X-API-Key' => $this->apiKey])
                ->get("{$this->baseUrl}/b2c/status/{$d->provider_reference}");
        } catch (\Throwable $e) {
            return DisbursementResult::failed($e->getMessage());
        }

        $body = $response->json() ?? [];
        return match (strtolower((string) ($body['status'] ?? ''))) {
            'success', 'completed' => DisbursementResult::settled($d->provider_reference, $body),
            'failed', 'rejected'   => DisbursementResult::failed((string) ($body['reason'] ?? 'Vodafone reported FAILED'), $body),
            default                => DisbursementResult::sent($d->provider_reference, $body),
        };
    }

    private function normalisedMsisdn(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (str_starts_with($digits, '233')) return $digits;
        if (str_starts_with($digits, '0'))   return '233' . substr($digits, 1);
        return '233' . $digits;
    }
}

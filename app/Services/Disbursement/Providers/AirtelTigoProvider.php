<?php

namespace App\Services\Disbursement\Providers;

use App\Enums\DisbursementChannel;
use App\Models\Disbursement;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Support\Facades\Http;

class AirtelTigoProvider implements DisbursementProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly int    $timeoutSeconds = 15,
    ) {}

    public function channel(): string
    {
        return DisbursementChannel::AirtelTigo->value;
    }

    public function send(Disbursement $d): DisbursementResult
    {
        $token = $this->acquireToken();
        if (! $token) return DisbursementResult::failed('AirtelTigo token acquisition failed.');

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->post("{$this->baseUrl}/disbursement/v1/transfer", [
                    'externalRef' => "CIHRMS-{$d->payroll_run_id}-{$d->id}",
                    'msisdn'      => $this->normalisedMsisdn($d->beneficiary_account),
                    'amount'      => (float) $d->net_to_recipient,
                    'currency'    => 'GHS',
                    'description' => "Salary — {$d->beneficiary_name}",
                ]);
        } catch (\Throwable $e) {
            return DisbursementResult::failed("AirtelTigo transport error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return DisbursementResult::failed("AirtelTigo HTTP {$response->status()}", ['body' => $response->body()]);
        }

        $body = $response->json() ?? [];
        $ref = (string) ($body['transactionId'] ?? $body['referenceId'] ?? '');
        return $ref !== ''
            ? DisbursementResult::sent($ref, $body)
            : DisbursementResult::failed('No transactionId in response', $body);
    }

    public function refreshStatus(Disbursement $d): DisbursementResult
    {
        $token = $this->acquireToken();
        if (! $token) return DisbursementResult::failed('AirtelTigo token acquisition failed.');

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->get("{$this->baseUrl}/disbursement/v1/transfer/{$d->provider_reference}");
        } catch (\Throwable $e) {
            return DisbursementResult::failed($e->getMessage());
        }

        $body = $response->json() ?? [];
        return match (strtoupper((string) ($body['status'] ?? ''))) {
            'COMPLETED', 'SUCCESS' => DisbursementResult::settled($d->provider_reference, $body),
            'FAILED', 'REJECTED'   => DisbursementResult::failed((string) ($body['reason'] ?? 'AirtelTigo reported FAILED'), $body),
            default                => DisbursementResult::sent($d->provider_reference, $body),
        };
    }

    private function acquireToken(): ?string
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->asForm()
                ->post("{$this->baseUrl}/oauth/token", [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);
        } catch (\Throwable $e) {
            return null;
        }
        return $response->successful() ? (string) ($response->json()['access_token'] ?? '') : null;
    }

    private function normalisedMsisdn(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (str_starts_with($digits, '233')) return $digits;
        if (str_starts_with($digits, '0'))   return '233' . substr($digits, 1);
        return '233' . $digits;
    }
}

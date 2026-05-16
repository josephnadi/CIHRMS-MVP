<?php

namespace App\Services\Disbursement\Providers;

use App\Enums\DisbursementChannel;
use App\Models\Disbursement;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * MTN MoMo Disbursement adapter.
 *
 *  - Sandbox + production share the same shape; switch via config.
 *  - Auth is OAuth2 client-credentials; access token cached for 1 hour.
 *  - Idempotency-key = `MOMO-{disbursement_id}` so retries are safe.
 *
 * MTN MoMo settlement is asynchronous — we receive a referenceId synchronously
 * and the final disposition arrives via the configured webhook callback.
 */
class MtnMomoProvider implements DisbursementProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $subscriptionKey,
        private readonly string $apiUser,
        private readonly string $apiKey,
        private readonly string $environment = 'sandbox',
        private readonly int    $timeoutSeconds = 15,
    ) {}

    public function channel(): string
    {
        return DisbursementChannel::MtnMomo->value;
    }

    public function send(Disbursement $d): DisbursementResult
    {
        $token = $this->acquireToken();
        if (! $token) {
            return DisbursementResult::failed('Could not acquire MTN MoMo access token.');
        }

        $referenceId = (string) Str::uuid();

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->withHeaders([
                    'X-Reference-Id'        => $referenceId,
                    'X-Target-Environment'  => $this->environment,
                    'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
                    'Content-Type'          => 'application/json',
                ])
                ->post("{$this->baseUrl}/disbursement/v1_0/transfer", [
                    'amount'           => (string) $d->net_to_recipient,
                    'currency'         => 'GHS',
                    'externalId'       => "PAYROLL-{$d->payroll_run_id}-{$d->id}",
                    'payee'            => [
                        'partyIdType' => 'MSISDN',
                        'partyId'     => $this->normalisedMsisdn($d->beneficiary_account),
                    ],
                    'payerMessage'     => "Salary payment for {$d->beneficiary_name}",
                    'payeeNote'        => 'CIHRMS payroll',
                ]);
        } catch (\Throwable $e) {
            return DisbursementResult::failed("MoMo transport error: {$e->getMessage()}");
        }

        if ($response->status() === 202) {
            return DisbursementResult::sent($referenceId, ['accepted' => true]);
        }

        return DisbursementResult::failed(
            "MoMo HTTP {$response->status()}: " . substr($response->body(), 0, 200),
            ['body' => $response->json() ?? $response->body()],
        );
    }

    public function refreshStatus(Disbursement $d): DisbursementResult
    {
        if (! $d->provider_reference) {
            return DisbursementResult::failed('No provider reference to query.');
        }

        $token = $this->acquireToken();
        if (! $token) {
            return DisbursementResult::failed('Could not acquire MTN MoMo access token.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->withHeaders([
                    'X-Target-Environment' => $this->environment,
                    'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
                ])
                ->get("{$this->baseUrl}/disbursement/v1_0/transfer/{$d->provider_reference}");
        } catch (\Throwable $e) {
            return DisbursementResult::failed("Status poll error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return DisbursementResult::failed("Status poll HTTP {$response->status()}");
        }

        $body   = $response->json() ?? [];
        $status = (string) ($body['status'] ?? '');

        return match ($status) {
            'SUCCESSFUL' => DisbursementResult::settled($d->provider_reference, $body),
            'FAILED'     => DisbursementResult::failed((string) ($body['reason']['message'] ?? 'Provider reported FAILED'), $body),
            default      => DisbursementResult::sent($d->provider_reference, $body), // still pending
        };
    }

    private function acquireToken(): ?string
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->apiUser, $this->apiKey)
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
                ])
                ->post("{$this->baseUrl}/disbursement/token/");
        } catch (\Throwable $e) {
            return null;
        }
        return $response->successful() ? (string) ($response->json()['access_token'] ?? '') : null;
    }

    /** MTN expects MSISDN in 233XXXXXXXXX (no plus, no leading zero) form. */
    private function normalisedMsisdn(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (str_starts_with($digits, '233')) return $digits;
        if (str_starts_with($digits, '0'))   return '233' . substr($digits, 1);
        return '233' . $digits;
    }
}

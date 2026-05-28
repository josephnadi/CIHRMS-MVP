<?php

namespace App\Services\Messaging\Sms\Providers;

use App\Services\Messaging\Sms\Contracts\SmsProvider;
use App\Services\Messaging\Sms\SmsResult;
use Illuminate\Support\Facades\Http;

/**
 * Hubtel SMS API adapter.
 *
 *   GET https://smsc.hubtel.com/v1/messages/send
 *     ?clientid=<id>&clientsecret=<secret>&from=<sender>&to=<msisdn>&content=<body>
 *
 * Returns a JSON envelope with `status` and `messageId` on accept.
 * Default sender ID is the org's pre-registered alphanumeric (e.g. "CIHRMS").
 */
class HubtelSmsProvider implements SmsProvider
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $defaultSender,
        private readonly string $baseUrl = 'https://smsc.hubtel.com',
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function name(): string
    {
        return 'hubtel';
    }

    public function send(string $toPhone, string $body, ?string $fromSender = null): SmsResult
    {
        $sender = $fromSender ?: $this->defaultSender;

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/v1/messages/send", [
                    'clientid'     => $this->clientId,
                    'clientsecret' => $this->clientSecret,
                    'from'         => $sender,
                    'to'           => $this->normalisedMsisdn($toPhone),
                    'content'      => $body,
                ]);
        } catch (\Throwable $e) {
            return SmsResult::failedTransient("Hubtel transport error: {$e->getMessage()}");
        }

        $payload = $response->json() ?? [];

        if ($response->serverError()) {
            return SmsResult::failedTransient(
                "Hubtel upstream (HTTP {$response->status()}): " . ($payload['statusDescription'] ?? 'unknown'),
                $payload,
            );
        }

        if (! $response->successful() || (int) ($payload['status'] ?? -1) !== 0) {
            return SmsResult::failed(
                "Hubtel rejected (HTTP {$response->status()}): " . ($payload['statusDescription'] ?? 'unknown'),
                $payload,
            );
        }

        return SmsResult::sent(
            messageId: (string) ($payload['messageId'] ?? ''),
            segments:  (int) ($payload['rate']  ?? 1),
            cost:      (float) ($payload['rate'] ?? 0),
            raw:       $payload,
        );
    }

    /** Hubtel accepts MSISDN as `233XXXXXXXXX` (no plus). */
    private function normalisedMsisdn(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (str_starts_with($digits, '233')) return $digits;
        if (str_starts_with($digits, '0'))   return '233' . substr($digits, 1);
        return '233' . $digits;
    }
}

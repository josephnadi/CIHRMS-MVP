<?php

namespace App\Services\Messaging\Sms\Providers;

use App\Services\Messaging\Sms\Contracts\SmsProvider;
use App\Services\Messaging\Sms\SmsResult;
use Illuminate\Support\Facades\Http;

/**
 * Twilio SMS provider — fallback for non-Ghana phone numbers or when Hubtel
 * is unavailable. Uses Twilio's REST API:
 *   POST https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json
 */
class TwilioSmsProvider implements SmsProvider
{
    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $fromNumber,    // E.164 ("+1...")
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function send(string $toPhone, string $body, ?string $fromSender = null): SmsResult
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                    'From' => $fromSender ?: $this->fromNumber,
                    'To'   => $this->toE164($toPhone),
                    'Body' => $body,
                ]);
        } catch (\Throwable $e) {
            return SmsResult::failedTransient("Twilio transport error: {$e->getMessage()}");
        }

        $payload = $response->json() ?? [];

        if ($response->serverError()) {
            return SmsResult::failedTransient(
                "Twilio upstream (HTTP {$response->status()}): " . ($payload['message'] ?? 'unknown'),
                $payload,
            );
        }

        if (! $response->successful()) {
            return SmsResult::failed(
                "Twilio rejected: " . ($payload['message'] ?? "HTTP {$response->status()}"),
                $payload,
            );
        }

        return SmsResult::sent(
            messageId: (string) ($payload['sid'] ?? ''),
            segments:  (int) ($payload['num_segments'] ?? 1),
            cost:      (float) ($payload['price'] ?? 0),
            raw:       $payload,
        );
    }

    private function toE164(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (str_starts_with($digits, '233')) return '+' . $digits;
        if (str_starts_with($digits, '0'))   return '+233' . substr($digits, 1);
        return '+' . $digits;
    }
}

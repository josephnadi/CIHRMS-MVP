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
            return SmsResult::failed("Twilio transport error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            $body = $response->json() ?? [];
            return SmsResult::failed(
                "Twilio rejected: " . ($body['message'] ?? "HTTP {$response->status()}"),
                $body,
            );
        }

        $body = $response->json() ?? [];
        return SmsResult::sent(
            messageId: (string) ($body['sid'] ?? ''),
            segments:  (int) ($body['num_segments'] ?? 1),
            cost:      (float) ($body['price'] ?? 0),
            raw:       $body,
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

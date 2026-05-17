<?php

namespace App\Services\Messaging\Sms\Providers;

use App\Services\Messaging\Sms\Contracts\SmsProvider;
use App\Services\Messaging\Sms\SmsResult;
use Illuminate\Support\Facades\Log;

/**
 * No-op provider for local / staging / pilot. Writes to the log channel
 * instead of sending real SMS. Picked when `messaging.sms.driver=log`.
 */
class LogSmsProvider implements SmsProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $toPhone, string $body, ?string $fromSender = null): SmsResult
    {
        Log::channel(config('messaging.sms.log_channel', 'stack'))->info('[SMS]', [
            'to'     => $toPhone,
            'from'   => $fromSender,
            'body'   => $body,
            'length' => mb_strlen($body),
        ]);

        return SmsResult::sent(
            messageId: 'log-' . uniqid('', true),
            segments:  (int) ceil(mb_strlen($body) / 160),
        );
    }
}

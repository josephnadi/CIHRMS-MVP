<?php

namespace App\Services\Messaging\Sms\Contracts;

use App\Services\Messaging\Sms\SmsResult;

/**
 * Pluggable SMS gateway provider. Concrete impls: Hubtel (Ghana-native,
 * default), mNotify, Twilio (international fallback). Active provider
 * selected by config/messaging.php.
 */
interface SmsProvider
{
    /** Provider slug — matches the `provider` enum value used on SmsMessage. */
    public function name(): string;

    /**
     * Push a message synchronously to the gateway. Returns acceptance/failure;
     * delivery confirmation arrives later via the inbound delivery-receipt
     * webhook.
     */
    public function send(string $toPhone, string $body, ?string $fromSender = null): SmsResult;
}

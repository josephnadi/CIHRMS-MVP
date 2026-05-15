<?php

namespace App\Integrations\Contracts;

use App\Integrations\DTO\MessageDto;

interface MessagingProvider extends IntegrationProvider
{
    /** Free-form text — only valid inside an active 24h conversation window for WhatsApp. */
    public function sendText(string $to, string $body): string;

    /** Pre-approved template message (HSM) — required for proactive WhatsApp notifications. */
    public function sendTemplate(string $to, string $templateName, array $params = [], ?string $language = null): string;

    public function sendMedia(string $to, string $mediaUrl, ?string $caption = null, string $type = 'image'): string;

    /** Convert a raw inbound webhook payload into our normalised DTO. */
    public function parseInbound(array $payload): ?MessageDto;
}

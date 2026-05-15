<?php

namespace App\Integrations\DTO;

final class MessageDto
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $from,
        public readonly ?string $to,
        public readonly string $body,
        public readonly string $type = 'text', // text|image|document|audio|interactive
        public readonly ?string $mediaUrl = null,
        public readonly ?\DateTimeInterface $receivedAt = null,
        public readonly array $raw = [],
    ) {}
}

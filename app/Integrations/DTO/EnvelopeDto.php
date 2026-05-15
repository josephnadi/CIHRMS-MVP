<?php

namespace App\Integrations\DTO;

final class EnvelopeDto
{
    /**
     * @param  array<int, array{email: string, name: string, role?: string}>  $recipients
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $message,
        public readonly string $documentBase64,
        public readonly string $documentName,
        public readonly array $recipients,
        public readonly ?string $callbackUrl = null,
    ) {}
}

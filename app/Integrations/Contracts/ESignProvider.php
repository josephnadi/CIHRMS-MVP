<?php

namespace App\Integrations\Contracts;

use App\Integrations\DTO\EnvelopeDto;

interface ESignProvider extends IntegrationProvider
{
    public function createEnvelope(EnvelopeDto $envelope): string;

    public function status(string $envelopeId): string;

    public function signedDocumentUrl(string $envelopeId): ?string;

    public function void(string $envelopeId, ?string $reason = null): bool;
}

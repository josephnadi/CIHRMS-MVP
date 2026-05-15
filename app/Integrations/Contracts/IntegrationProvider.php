<?php

namespace App\Integrations\Contracts;

use App\Models\Integration;

/**
 * Base contract for every external provider driver.
 *
 * Every concrete driver (ZohoCrmDriver, MsGraphFilesDriver, WhatsAppCloudDriver, ...)
 * MUST implement this and one or more capability contracts (CrmProvider, FileStorageProvider, ...).
 */
interface IntegrationProvider
{
    public function provider(): string;

    public function capability(): string;

    public function displayName(): string;

    public function bind(Integration $integration): static;

    public function isConfigured(): bool;

    /**
     * Lightweight connectivity probe — implementations should call a cheap endpoint
     * (e.g. /me or /ping) to confirm the stored token is still valid.
     */
    public function ping(): bool;
}

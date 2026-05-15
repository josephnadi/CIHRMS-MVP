<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\IntegrationProvider;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Convenience base for concrete drivers — handles the IntegrationProvider boilerplate
 * (binding, configuration access) and provides a small wrapper that logs every
 * outbound call into integration_events automatically.
 */
abstract class AbstractDriver implements IntegrationProvider
{
    protected ?Integration $integration = null;

    abstract public function provider(): string;

    abstract public function capability(): string;

    abstract public function displayName(): string;

    public function bind(Integration $integration): static
    {
        $this->integration = $integration;

        return $this;
    }

    public function isConfigured(): bool
    {
        $cfg = config("integrations.drivers.{$this->provider()}", []);
        $required = $this->requiredConfigKeys();

        foreach ($required as $key) {
            if (empty($cfg[$key])) {
                return false;
            }
        }

        return true;
    }

    public function ping(): bool
    {
        return $this->isConfigured();
    }

    /** @return array<int, string> */
    protected function requiredConfigKeys(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function driverConfig(): array
    {
        return config("integrations.drivers.{$this->provider()}", []);
    }

    /**
     * Wrap an outbound call so every attempt is logged into integration_events.
     */
    protected function track(string $eventType, array $payload, callable $callable): mixed
    {
        $event = $this->integration
            ? IntegrationEvent::create([
                'integration_id' => $this->integration->id,
                'direction'      => IntegrationEvent::DIRECTION_OUTBOUND,
                'event_type'     => $eventType,
                'payload'        => $payload,
                'status'         => IntegrationEvent::STATUS_QUEUED,
                'attempts'       => 1,
            ])
            : null;

        try {
            $result = $callable();
            $event?->markSent(is_array($result) ? $result : ['result' => $result]);

            return $result;
        } catch (Throwable $e) {
            Log::error("[integration:{$this->provider()}] {$eventType} failed", [
                'message' => $e->getMessage(),
                'payload' => $payload,
            ]);
            $event?->markFailed($e->getMessage());
            throw $e;
        }
    }
}

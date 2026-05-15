<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * Central resolver for integration drivers.
 *
 * Usage:
 *   IntegrationManager::for('crm')->syncContact(...)
 *   IntegrationManager::driver('zoho_crm', 'crm')->syncContact(...)
 */
class IntegrationManager
{
    /** @var array<string, IntegrationProvider> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /** Resolve the active driver for a capability via config. */
    public function for(string $capability): IntegrationProvider
    {
        $provider = config("integrations.capabilities.{$capability}");

        if (! $provider) {
            throw new InvalidArgumentException("No driver configured for capability [{$capability}].");
        }

        return $this->driver($provider, $capability);
    }

    public function driver(string $provider, string $capability): IntegrationProvider
    {
        $cacheKey = "{$provider}:{$capability}";
        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $cfg = config("integrations.drivers.{$provider}");
        if (! $cfg) {
            throw new RuntimeException("Provider config for [{$provider}] not found.");
        }

        // Multi-capability providers (e.g. ms_graph → files/spreadsheet/calendar)
        // declare per-capability driver classes under `capability_classes`.
        // The legacy `class` key remains as the default / single-capability driver.
        $class = $cfg['capability_classes'][$capability] ?? $cfg['class'] ?? null;
        if (! $class || ! class_exists($class)) {
            throw new RuntimeException("Driver class for [{$provider}/{$capability}] not found.");
        }

        /** @var IntegrationProvider $driver */
        $driver = $this->container->make($class);

        // One OAuth row per provider; tokens shared across all capabilities of that provider.
        $integration = Integration::query()->where('provider', $provider)->first();

        if ($integration) {
            $driver->bind($integration);
        }

        return $this->resolved[$cacheKey] = $driver;
    }

    /** True when the configured driver for a capability has stored credentials. */
    public function isAvailable(string $capability): bool
    {
        try {
            $driver = $this->for($capability);
        } catch (\Throwable) {
            return false;
        }

        return $driver->isConfigured();
    }

    /** @return array<string, IntegrationProvider> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys((array) config('integrations.capabilities', [])) as $capability) {
            try {
                $out[$capability] = $this->for($capability);
            } catch (\Throwable) {
                // capability not configured yet — skip
            }
        }

        return $out;
    }

    /** @return list<array{provider:string, capability:string, class:string}> */
    public function catalogue(): array
    {
        $out = [];
        foreach ((array) config('integrations.drivers', []) as $provider => $cfg) {
            $hasAny = ! empty($cfg['class']) || ! empty($cfg['capability_classes']);
            if (! $hasAny) {
                continue;
            }
            $capability = $this->capabilityForProvider($provider);
            $out[] = [
                'provider'   => $provider,
                'capability' => $capability,
                'class'      => $cfg['class'] ?? array_values($cfg['capability_classes'] ?? [])[0] ?? null,
            ];
        }

        return $out;
    }

    protected function capabilityForProvider(string $provider): string
    {
        // First preference: the capability the user actually routes to this provider.
        foreach ((array) config('integrations.capabilities', []) as $capability => $configured) {
            if ($configured === $provider) {
                return $capability;
            }
        }

        // Fallback: first capability declared by the driver (so multi-capability providers
        // like Google still display a sensible primary capability badge in the marketplace).
        $declared = (array) config("integrations.drivers.{$provider}.capability_classes", []);
        if (! empty($declared)) {
            return (string) array_key_first($declared);
        }

        // Last resort: ask the driver itself — single-capability drivers (DocuSign, Zoho Sign,
        // WhatsApp, Slack) declare their capability in the contract.
        $class = config("integrations.drivers.{$provider}.class");
        if ($class && class_exists($class)) {
            try {
                /** @var IntegrationProvider $instance */
                $instance = $this->container->make($class);
                return $instance->capability();
            } catch (\Throwable) {
                // ignore — fall through to unknown
            }
        }

        return 'unknown';
    }
}

<?php

namespace App\Integrations\Facades;

use App\Integrations\Contracts\IntegrationProvider;
use App\Integrations\IntegrationManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static IntegrationProvider for(string $capability)
 * @method static IntegrationProvider driver(string $provider, string $capability)
 * @method static bool isAvailable(string $capability)
 * @method static array all()
 * @method static array catalogue()
 */
class Integrations extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IntegrationManager::class;
    }
}

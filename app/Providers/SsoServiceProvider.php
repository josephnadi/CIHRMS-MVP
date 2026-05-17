<?php

namespace App\Providers;

use App\Enums\SsoProviderType;
use App\Services\Sso\OidcSsoAdapter;
use App\Services\Sso\SamlSsoAdapter;
use App\Services\Sso\SsoOrchestrator;
use Illuminate\Support\ServiceProvider;

class SsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OidcSsoAdapter::class);
        $this->app->singleton(SamlSsoAdapter::class);

        $this->app->singleton(SsoOrchestrator::class, function ($app) {
            return new SsoOrchestrator([
                SsoProviderType::Oidc->value => $app->make(OidcSsoAdapter::class),
                SsoProviderType::Saml->value => $app->make(SamlSsoAdapter::class),
            ]);
        });
    }
}

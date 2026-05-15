<?php

namespace App\Providers;

use App\Enums\IdentityProviderKind;
use App\Services\Identity\Contracts\IdentityVerificationProvider;
use App\Services\Identity\Providers\ManualUploadProvider;
use App\Services\Identity\Providers\NiaOfficialProvider;
use App\Services\Identity\Providers\ThirdPartyKycProvider;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IdentityVerificationProvider::class, function ($app) {
            $driver = config('identity.driver', 'manual_upload');
            $cfg    = config("identity.providers.{$driver}", []);

            return match ($driver) {
                IdentityProviderKind::NiaOfficial->value   => new NiaOfficialProvider(
                    baseUrl: (string) ($cfg['base_url'] ?? ''),
                    apiKey:  (string) ($cfg['api_key']  ?? ''),
                    timeoutSeconds: (int) ($cfg['timeout'] ?? 8),
                ),
                IdentityProviderKind::ThirdPartyKyc->value => new ThirdPartyKycProvider(
                    baseUrl: (string) ($cfg['base_url'] ?? ''),
                    apiKey:  (string) ($cfg['api_key']  ?? ''),
                    vendor:  (string) ($cfg['vendor']   ?? 'unspecified'),
                    timeoutSeconds: (int) ($cfg['timeout'] ?? 10),
                ),
                default => new ManualUploadProvider(),
            };
        });
    }
}

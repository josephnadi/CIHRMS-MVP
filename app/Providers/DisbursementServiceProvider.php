<?php

namespace App\Providers;

use App\Enums\DisbursementChannel;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\Providers\AirtelTigoProvider;
use App\Services\Disbursement\Providers\MtnMomoProvider;
use App\Services\Disbursement\Providers\VodafoneCashProvider;
use Illuminate\Support\ServiceProvider;

class DisbursementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BatchDisbursementService::class, function ($app) {
            $providers = [];

            $cfg = config('disbursement.providers');

            if (! empty($cfg['mtn_momo']['enabled'])) {
                $providers[DisbursementChannel::MtnMomo->value] = new MtnMomoProvider(
                    baseUrl:         (string) $cfg['mtn_momo']['base_url'],
                    subscriptionKey: (string) $cfg['mtn_momo']['subscription_key'],
                    apiUser:         (string) $cfg['mtn_momo']['api_user'],
                    apiKey:          (string) $cfg['mtn_momo']['api_key'],
                    environment:     (string) ($cfg['mtn_momo']['environment'] ?? 'sandbox'),
                );
            }

            if (! empty($cfg['vodafone_cash']['enabled'])) {
                $providers[DisbursementChannel::VodafoneCash->value] = new VodafoneCashProvider(
                    baseUrl:       (string) $cfg['vodafone_cash']['base_url'],
                    apiKey:        (string) $cfg['vodafone_cash']['api_key'],
                    signingSecret: (string) $cfg['vodafone_cash']['signing_secret'],
                );
            }

            if (! empty($cfg['airtel_tigo']['enabled'])) {
                $providers[DisbursementChannel::AirtelTigo->value] = new AirtelTigoProvider(
                    baseUrl:      (string) $cfg['airtel_tigo']['base_url'],
                    clientId:     (string) $cfg['airtel_tigo']['client_id'],
                    clientSecret: (string) $cfg['airtel_tigo']['client_secret'],
                );
            }

            return new BatchDisbursementService($providers);
        });
    }
}

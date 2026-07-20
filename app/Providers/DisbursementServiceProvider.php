<?php

namespace App\Providers;

use App\Enums\DisbursementChannel;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\GhIpssBatchFileBuilder;
use App\Services\Disbursement\Providers\AirtelTigoProvider;
use App\Services\Disbursement\Providers\GhIpssAchProvider;
use App\Services\Disbursement\Providers\HubtelBankProvider;
use App\Services\Disbursement\Providers\MtnMomoProvider;
use App\Services\Disbursement\Providers\VodafoneCashProvider;
use App\Services\Finance\PostingService;
use Illuminate\Support\ServiceProvider;

class DisbursementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BatchDisbursementService::class, function ($app) {
            $providers = [];

            $cfg = config('disbursement.providers');

            if (! empty($cfg['ghipss_ach']['enabled'])) {
                $providers[DisbursementChannel::GhipssAch->value] = new GhIpssAchProvider(
                    sponsorSortCode: (string) ($cfg['ghipss_ach']['sponsor_sort_code'] ?? ''),
                    originatorName:  (string) ($cfg['ghipss_ach']['originator_name']   ?? ''),
                );
            }

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

            if (! empty($cfg['hubtel_bank']['enabled'])) {
                $providers[DisbursementChannel::HubtelBank->value] = new HubtelBankProvider(
                    baseUrl:         (string) $cfg['hubtel_bank']['base_url'],
                    clientId:        (string) $cfg['hubtel_bank']['client_id'],
                    clientSecret:    (string) $cfg['hubtel_bank']['client_secret'],
                    merchantAccount: (string) $cfg['hubtel_bank']['merchant_account'],
                    callbackUrl:     (string) $cfg['hubtel_bank']['callback_url'],
                );
            }

            return new BatchDisbursementService($providers, $app->make(PostingService::class));
        });

        $this->app->singleton(GhIpssBatchFileBuilder::class, function ($app) {
            $cfg = config('disbursement.providers.ghipss_ach', []);
            return new GhIpssBatchFileBuilder(
                sponsorSortCode: (string) ($cfg['sponsor_sort_code'] ?? ''),
                originatorName:  (string) ($cfg['originator_name']   ?? ''),
                disk:            (string) ($cfg['output_disk']       ?? 'local'),
            );
        });
    }
}

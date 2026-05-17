<?php

namespace App\Providers;

use App\Services\Messaging\Sms\Contracts\SmsProvider;
use App\Services\Messaging\Sms\Providers\HubtelSmsProvider;
use App\Services\Messaging\Sms\Providers\LogSmsProvider;
use App\Services\Messaging\Sms\Providers\TwilioSmsProvider;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProvider::class, function () {
            $driver = config('messaging.sms.driver', 'log');

            return match ($driver) {
                'hubtel' => new HubtelSmsProvider(
                    clientId:      (string) config('messaging.sms.hubtel.client_id'),
                    clientSecret:  (string) config('messaging.sms.hubtel.client_secret'),
                    defaultSender: (string) config('messaging.sms.hubtel.default_sender', 'CIHRMS'),
                    baseUrl:       (string) config('messaging.sms.hubtel.base_url'),
                ),
                'twilio' => new TwilioSmsProvider(
                    accountSid: (string) config('messaging.sms.twilio.account_sid'),
                    authToken:  (string) config('messaging.sms.twilio.auth_token'),
                    fromNumber: (string) config('messaging.sms.twilio.from_number'),
                ),
                default  => new LogSmsProvider(),
            };
        });

        $this->app->singleton(SmsDispatcher::class);
    }
}

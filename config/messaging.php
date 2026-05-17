<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active SMS driver
    |--------------------------------------------------------------------------
    | Supported: hubtel | twilio | log. `log` is the no-op driver for local
    | and staging that just writes to the configured log channel.
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),

        'hubtel' => [
            'client_id'      => env('HUBTEL_CLIENT_ID'),
            'client_secret'  => env('HUBTEL_CLIENT_SECRET'),
            'default_sender' => env('HUBTEL_SENDER_ID', 'CIHRMS'),
            'base_url'       => env('HUBTEL_BASE_URL', 'https://smsc.hubtel.com'),
            'webhook_secret' => env('HUBTEL_WEBHOOK_SECRET'),
        ],

        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token'  => env('TWILIO_AUTH_TOKEN'),
            'from_number' => env('TWILIO_FROM_NUMBER'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | USSD
    |--------------------------------------------------------------------------
    */
    'ussd' => [
        'shortcode'          => env('USSD_SHORTCODE', '*920*HR#'),
        'session_ttl_seconds'=> 180,            // USSD sessions are short-lived
        'pin_max_attempts'   => 5,
        'pin_lock_minutes'   => 15,
        'webhook_secret'     => env('USSD_WEBHOOK_SECRET'),
    ],
];

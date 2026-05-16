<?php

return [
    /*
    |--------------------------------------------------------------------------
    | E-Levy rate fallback (Electronic Transfer Levy Act 2022, Act 1075)
    |--------------------------------------------------------------------------
    | Preferred path is the `statutory_rates` row with code = 'E_LEVY_RATE',
    | which is effective-dated and rolls cleanly through rate changes. This
    | env var is the fallback if no row is seeded.
    */
    'e_levy_rate_fallback' => env('E_LEVY_RATE_FALLBACK', 0.015),

    /*
    |--------------------------------------------------------------------------
    | Active providers per channel
    |--------------------------------------------------------------------------
    | One bind per mobile-money provider. Set `enabled: false` to fall back to
    | manual handling of that channel for the period (e.g. provider outage).
    */
    'providers' => [
        'mtn_momo' => [
            'enabled'          => env('MOMO_MTN_ENABLED', false),
            'base_url'         => env('MOMO_MTN_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
            'subscription_key' => env('MOMO_MTN_SUBSCRIPTION_KEY'),
            'api_user'         => env('MOMO_MTN_API_USER'),
            'api_key'          => env('MOMO_MTN_API_KEY'),
            'environment'      => env('MOMO_MTN_ENVIRONMENT', 'sandbox'),
        ],
        'vodafone_cash' => [
            'enabled'        => env('MOMO_VF_ENABLED', false),
            'base_url'       => env('MOMO_VF_BASE_URL'),
            'api_key'        => env('MOMO_VF_API_KEY'),
            'signing_secret' => env('MOMO_VF_SIGNING_SECRET'),
        ],
        'airtel_tigo' => [
            'enabled'       => env('MOMO_AT_ENABLED', false),
            'base_url'      => env('MOMO_AT_BASE_URL'),
            'client_id'     => env('MOMO_AT_CLIENT_ID'),
            'client_secret' => env('MOMO_AT_CLIENT_SECRET'),
        ],
    ],
];

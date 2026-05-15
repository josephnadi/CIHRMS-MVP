<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Ghana Card / NIA verification provider
    |--------------------------------------------------------------------------
    |
    | Supported values:
    |   - nia_official    : Direct NIA Identity Verification System (requires MoU)
    |   - third_party_kyc : uqudo / Smile ID / Youverify / etc.
    |   - manual_upload   : Pilot fallback — HR uploads a scan, senior officer approves
    */
    'driver' => env('IDENTITY_PROVIDER', 'manual_upload'),

    'providers' => [
        'nia_official' => [
            'base_url' => env('NIA_BASE_URL', 'https://api.nia.gov.gh'),
            'api_key'  => env('NIA_API_KEY'),
            'timeout'  => env('NIA_TIMEOUT', 8),
        ],

        'third_party_kyc' => [
            'base_url' => env('KYC_BASE_URL'),
            'api_key'  => env('KYC_API_KEY'),
            'vendor'   => env('KYC_VENDOR', 'uqudo'),
            'timeout'  => env('KYC_TIMEOUT', 10),
        ],
    ],

    'verification_validity_months' => env('IDENTITY_VALIDITY_MONTHS', 12),
];

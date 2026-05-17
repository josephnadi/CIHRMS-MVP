<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supported locales
    |--------------------------------------------------------------------------
    | The same list also lives on App\Enums\AppLocale. Keep them in sync.
    | Order here controls the order in the locale-switcher dropdown.
    */
    'supported' => [
        'en' => ['label' => 'English',      'native' => 'English'],
        'tw' => ['label' => 'Twi',          'native' => 'Twi (Akan)'],
        'ga' => ['label' => 'Ga',           'native' => 'Gã'],
        'ee' => ['label' => 'Ewe',          'native' => 'Eʋegbe'],
    ],

    'default' => env('APP_LOCALE', 'en'),

    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),
];

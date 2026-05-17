<?php

use App\Providers\AppServiceProvider;
use App\Providers\IdentityServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    \App\Providers\DisbursementServiceProvider::class,
    \App\Providers\MessagingServiceProvider::class,
    \App\Providers\SsoServiceProvider::class,
];

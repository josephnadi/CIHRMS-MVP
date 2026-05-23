<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('payment-intent expire job is scheduled', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $found = collect($schedule->events())->first(function ($event) {
        $description = strtolower((string) ($event->description ?? ''));
        $command     = strtolower((string) ($event->command ?? ''));
        return str_contains($description, 'expire-stale')
            || str_contains($description, 'expirestale')
            || str_contains($description, 'payment-intents:expire')
            || str_contains($command, 'expirestale')
            || str_contains($command, 'payment-intents');
    });

    expect($found)->not->toBeNull('PaymentIntentService::expireStale() is not on a schedule');
});

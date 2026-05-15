<?php

declare(strict_types=1);

use App\Models\AnalyticsEvent;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Carbon::setTestNow('2026-05-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('returns a 30-day time-series with zero-filled missing days', function () {
    $user = User::factory()->create();

    AnalyticsEvent::factory()->create([
        'event'      => 'employee.created',
        'created_at' => now()->subDays(2),
    ]);
    AnalyticsEvent::factory()->create([
        'event'      => 'employee.created',
        'created_at' => now()->subDays(2),
    ]);
    AnalyticsEvent::factory()->create([
        'event'      => 'employee.created',
        'created_at' => now()->subDays(10),
    ]);

    $series = app(DashboardService::class)->timeSeries('employees', 30);

    expect($series)->toBeArray()->toHaveCount(30);

    $byDate = collect($series)->keyBy('date');

    expect($byDate->get(now()->subDays(2)->toDateString())['value'])->toBe(2);
    expect($byDate->get(now()->subDays(10)->toDateString())['value'])->toBe(1);
    expect($byDate->get(now()->subDays(5)->toDateString())['value'])->toBe(0);
});

it('caches the time-series for 60 seconds per metric', function () {
    AnalyticsEvent::factory()->create([
        'event'      => 'ticket.created',
        'created_at' => now()->subDay(),
    ]);

    $first = app(DashboardService::class)->timeSeries('open_tickets', 7);

    AnalyticsEvent::factory()->create([
        'event'      => 'ticket.created',
        'created_at' => now()->subDay(),
    ]);

    $second = app(DashboardService::class)->timeSeries('open_tickets', 7);

    expect($second)->toBe($first);
});

it('throws on unsupported metric', function () {
    expect(fn () => app(DashboardService::class)->timeSeries('not_a_metric', 30))
        ->toThrow(InvalidArgumentException::class);
});

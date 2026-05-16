<?php

use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Http;

it('signs the payload with sha256 HMAC of the body using the subscription secret', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $sub = WebhookSubscription::create([
        'name'           => 'BI feed',
        'target_url'     => 'https://example.test/webhook',
        'signing_secret' => 'super-secret-32-byte-string-aaaaa',
        'event_types'    => ['payroll.run.approved'],
        'is_active'      => true,
    ]);

    $dispatcher = app(WebhookDispatcher::class);
    $dispatcher->fanOut('payroll.run.approved', ['run_id' => 42, 'totals' => ['gross' => 1000]]);

    Http::assertSent(function ($request) use ($sub) {
        $body = $request->body();
        $expected = 'sha256=' . hash_hmac('sha256', $body, 'super-secret-32-byte-string-aaaaa');
        return $request->url() === $sub->target_url
            && $request->hasHeader('X-CIHRMS-Signature', $expected)
            && $request->hasHeader('X-CIHRMS-Event', 'payroll.run.approved');
    });

    expect($sub->fresh()->consecutive_failures)->toBe(0);
    expect($sub->fresh()->last_success_at)->not->toBeNull();
});

it('only delivers to subscriptions that match the event type (wildcard or exact)', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    WebhookSubscription::create([
        'name' => 'Only loans', 'target_url' => 'https://loans.test',
        'signing_secret' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'event_types' => ['loan.approved'], 'is_active' => true,
    ]);
    WebhookSubscription::create([
        'name' => 'All events', 'target_url' => 'https://all.test',
        'signing_secret' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'event_types' => ['*'], 'is_active' => true,
    ]);
    WebhookSubscription::create([
        'name' => 'Inactive', 'target_url' => 'https://inactive.test',
        'signing_secret' => 'cccccccccccccccccccccccccccccccc',
        'event_types' => ['payroll.run.approved'], 'is_active' => false,
    ]);

    $dispatcher = app(WebhookDispatcher::class);
    $count = $dispatcher->fanOut('payroll.run.approved', ['run_id' => 1]);

    // Wildcard match only (loan-only and inactive subs are skipped).
    expect($count)->toBe(1);
    Http::assertSent(fn ($r) => $r->url() === 'https://all.test');
    Http::assertNotSent(fn ($r) => $r->url() === 'https://loans.test');
    Http::assertNotSent(fn ($r) => $r->url() === 'https://inactive.test');
});

it('auto-disables a subscription after 10 consecutive failures', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $sub = WebhookSubscription::create([
        'name' => 'Flaky', 'target_url' => 'https://flaky.test',
        'signing_secret' => 'dddddddddddddddddddddddddddddddd',
        'event_types' => ['*'], 'is_active' => true,
        'consecutive_failures' => 9,    // one away from the limit
    ]);

    app(WebhookDispatcher::class)->fanOut('any.event', ['x' => 1]);

    $fresh = $sub->fresh();
    expect($fresh->consecutive_failures)->toBe(10);
    expect($fresh->is_active)->toBeFalse();
});

<?php

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Api\WebhookDispatcher;
use Illuminate\Support\Facades\Http;

it('signs the delivery payload with HMAC-SHA256 over timestamp + body', function () {
    Http::fake(['*' => Http::response(['ack' => true], 200)]);

    $sub = WebhookSubscription::create([
        'name'           => 'Test partner',
        'target_url'     => 'https://example.test/webhook',
        'signing_secret' => 'super-secret-123',
        'event_types'    => ['payroll.run.approved'],
        'is_active'      => true,
        'created_by'     => User::factory()->create()->id,
    ]);

    $delivered = app(WebhookDispatcher::class)->dispatch('payroll.run.approved', ['run_id' => 42]);

    expect($delivered)->toBe(1);

    Http::assertSent(function ($request) {
        $body      = $request->body();
        $timestamp = $request->header('X-CIHRMS-Timestamp')[0] ?? null;
        $signature = $request->header('X-CIHRMS-Signature')[0] ?? null;

        if (! $timestamp || ! $signature) return false;

        $expected = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}", 'super-secret-123');
        return $signature === $expected
            && $request->hasHeader('X-CIHRMS-Event', 'payroll.run.approved')
            && str_contains($body, '"event":"payroll.run.approved"')
            && str_contains($body, '"run_id":42');
    });

    expect($sub->fresh()->consecutive_failures)->toBe(0);
});

it('skips inactive subscriptions and ones not subscribed to the event', function () {
    Http::fake();

    $u = User::factory()->create();

    // Inactive — should NOT receive
    WebhookSubscription::create([
        'name' => 'Disabled', 'target_url' => 'https://example.test/a',
        'signing_secret' => 'x', 'event_types' => ['*'], 'is_active' => false,
        'created_by' => $u->id,
    ]);

    // Wrong event — should NOT receive
    WebhookSubscription::create([
        'name' => 'Wrong event', 'target_url' => 'https://example.test/b',
        'signing_secret' => 'x', 'event_types' => ['identity.verified'], 'is_active' => true,
        'created_by' => $u->id,
    ]);

    $delivered = app(WebhookDispatcher::class)->dispatch('payroll.run.approved', []);

    expect($delivered)->toBe(0);
    Http::assertSentCount(0);
});

it('auto-deactivates after 10 consecutive failures', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $sub = WebhookSubscription::create([
        'name'                 => 'Flaky',
        'target_url'           => 'https://example.test/webhook',
        'signing_secret'       => 'x',
        'event_types'          => ['*'],
        'is_active'            => true,
        'consecutive_failures' => 0,
        'created_by'           => User::factory()->create()->id,
    ]);

    for ($i = 0; $i < WebhookDispatcher::AUTO_DEACTIVATE_AT_FAILURES; $i++) {
        app(WebhookDispatcher::class)->dispatch('payroll.run.approved', ['i' => $i]);
    }

    $fresh = $sub->fresh();
    expect($fresh->is_active)->toBeFalse();
    expect($fresh->consecutive_failures)->toBeGreaterThanOrEqual(WebhookDispatcher::AUTO_DEACTIVATE_AT_FAILURES);
});

it('records delivery rows with response code', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $sub = WebhookSubscription::create([
        'name'           => 'Test',
        'target_url'     => 'https://example.test/x',
        'signing_secret' => 'x',
        'event_types'    => ['*'],
        'is_active'      => true,
        'created_by'     => User::factory()->create()->id,
    ]);

    app(WebhookDispatcher::class)->dispatch('identity.verified', ['employee_id' => 7]);

    $delivery = WebhookDelivery::where('subscription_id', $sub->id)->first();
    expect($delivery)->not->toBeNull();
    expect($delivery->status)->toBe('delivered');
    expect($delivery->response_code)->toBe(200);
    expect($delivery->event_type)->toBe('identity.verified');
});

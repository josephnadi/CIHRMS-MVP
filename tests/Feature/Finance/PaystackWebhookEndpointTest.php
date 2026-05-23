<?php

declare(strict_types=1);

use App\Jobs\ProcessPaystackWebhook;
use App\Models\PaystackWebhookEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.paystack.webhook_secret' => 'whsec_test_value']);
});

function signedPayload(array $payload, string $secret): array
{
    $body = json_encode($payload);
    return [$body, hash_hmac('sha512', $body, $secret)];
}

it('valid signature persists event row and dispatches job', function () {
    Queue::fake();

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 12345,
            'reference' => 'pst_endpoint_001',
            'status' => 'success',
            'amount' => 50000,
        ],
    ];
    [$body, $sig] = signedPayload($payload, 'whsec_test_value');

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    expect(PaystackWebhookEvent::count())->toBe(1);
    $event = PaystackWebhookEvent::first();
    expect($event->paystack_event_id)->toBe('12345');
    expect($event->event_type)->toBe('charge.success');
    expect($event->paystack_reference)->toBe('pst_endpoint_001');

    Queue::assertPushed(ProcessPaystackWebhook::class);
});

it('invalid signature returns 400 and creates no event row', function () {
    $payload = ['event' => 'charge.success', 'data' => ['id' => 99]];
    $body = json_encode($payload);

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => 'bad-sig', 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertStatus(400);

    expect(PaystackWebhookEvent::count())->toBe(0);
});

it('missing signature header returns 400', function () {
    $payload = ['event' => 'charge.success', 'data' => ['id' => 99]];
    $body = json_encode($payload);

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        $body
    )->assertStatus(400);
});

it('replayed payload is idempotent (one event row only)', function () {
    Queue::fake();

    $payload = [
        'event' => 'charge.success',
        'data' => ['id' => 7777, 'reference' => 'pst_replay_001', 'status' => 'success', 'amount' => 10000],
    ];
    [$body, $sig] = signedPayload($payload, 'whsec_test_value');

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    expect(PaystackWebhookEvent::count())->toBe(1);
});

it('webhook route is public — no auth redirect', function () {
    $payload = ['event' => 'charge.success', 'data' => ['id' => 1]];
    [$body, $sig] = signedPayload($payload, 'whsec_test_value');

    $response = $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    );

    expect($response->status())->not->toBe(302);
});

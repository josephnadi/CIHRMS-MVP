<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\PaymentIntent;
use App\Models\PaystackWebhookEvent;
use App\Models\User;

it('creates a payment intent and casts status enum + decimals + json', function () {
    $u = User::factory()->create();
    $c = Customer::factory()->create();

    $intent = PaymentIntent::create([
        'reference'   => 'PI-2026-000001',
        'customer_id' => $c->id,
        'amount'      => 250.50,
        'currency'    => 'GHS',
        'status'      => PaymentIntentStatus::Created->value,
        'created_by'  => $u->id,
        'last_paystack_response' => ['foo' => 'bar'],
    ]);

    expect($intent->status)->toBe(PaymentIntentStatus::Created);
    expect((float) $intent->amount)->toBe(250.50);
    expect($intent->last_paystack_response)->toBe(['foo' => 'bar']);
    expect($intent->customer->id)->toBe($c->id);
});

it('PaymentIntent.scopePending filters to status = pending', function () {
    $u = User::factory()->create();
    $c = Customer::factory()->create();

    PaymentIntent::create(['reference' => 'P1', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'pending', 'created_by' => $u->id]);
    PaymentIntent::create(['reference' => 'P2', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'success', 'created_by' => $u->id]);

    expect(PaymentIntent::pending()->pluck('reference')->all())->toBe(['P1']);
});

it('PaymentIntent.scopeStale returns pending intents with expires_at < now', function () {
    $u = User::factory()->create();
    $c = Customer::factory()->create();

    PaymentIntent::create(['reference' => 'old', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'pending', 'expires_at' => now()->subHour(), 'created_by' => $u->id]);
    PaymentIntent::create(['reference' => 'fresh', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'pending', 'expires_at' => now()->addHour(), 'created_by' => $u->id]);

    expect(PaymentIntent::stale()->pluck('reference')->all())->toBe(['old']);
});

it('PaystackWebhookEvent persists payload as JSON', function () {
    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_test_001',
        'event_type'         => 'charge.success',
        'paystack_reference' => 'pst_ref_001',
        'payload'            => ['data' => ['amount' => 25050]],
        'signature'          => 'abc123',
    ]);

    expect($event->payload)->toBe(['data' => ['amount' => 25050]]);
    expect($event->event_type)->toBe('charge.success');
    expect($event->processed_at)->toBeNull();
});

it('PaystackWebhookEvent.paystack_event_id is unique', function () {
    PaystackWebhookEvent::create([
        'paystack_event_id' => 'evt_dup', 'event_type' => 'charge.success',
        'payload' => [], 'signature' => 'sig',
    ]);

    expect(fn () => PaystackWebhookEvent::create([
        'paystack_event_id' => 'evt_dup', 'event_type' => 'charge.success',
        'payload' => [], 'signature' => 'sig',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

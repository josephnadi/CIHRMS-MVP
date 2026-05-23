<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the payment_intents table', function () {
    expect(Schema::hasTable('payment_intents'))->toBeTrue();
    expect(Schema::hasColumns('payment_intents', [
        'id', 'reference', 'customer_id', 'ar_invoice_id',
        'amount', 'currency', 'status',
        'paystack_reference', 'paystack_access_code', 'authorization_url', 'callback_url',
        'ar_receipt_id', 'narration', 'paid_at', 'expires_at', 'last_paystack_response',
        'created_by', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the paystack_webhook_events table', function () {
    expect(Schema::hasTable('paystack_webhook_events'))->toBeTrue();
    expect(Schema::hasColumns('paystack_webhook_events', [
        'id', 'paystack_event_id', 'event_type', 'paystack_reference',
        'payload', 'signature', 'payment_intent_id', 'ar_receipt_id',
        'processed_at', 'processing_error', 'received_at',
    ]))->toBeTrue();
});

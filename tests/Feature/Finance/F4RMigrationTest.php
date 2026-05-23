<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds refund audit columns to payment_intents', function () {
    expect(Schema::hasColumns('payment_intents', [
        'refunded_at', 'refund_amount', 'refund_reason',
        'refund_paystack_ref', 'refund_settled_at', 'refunded_by',
    ]))->toBeTrue();
});

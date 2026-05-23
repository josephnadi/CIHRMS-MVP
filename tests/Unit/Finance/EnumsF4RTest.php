<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;

it('PaymentIntentStatus includes Refunded case for F4-R', function () {
    $values = array_map(fn ($c) => $c->value, PaymentIntentStatus::cases());
    expect($values)->toContain('refunded');
});

it('PaymentIntentStatus::Refunded has a non-empty label', function () {
    expect(PaymentIntentStatus::Refunded->label())->toBeString()->not->toBeEmpty();
});

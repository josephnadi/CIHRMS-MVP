<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;

it('PaymentIntentStatus exposes 6 cases', function () {
    $values = array_map(fn ($c) => $c->value, PaymentIntentStatus::cases());
    expect($values)->toEqualCanonicalizing([
        'created', 'pending', 'success', 'failed', 'abandoned', 'expired',
    ]);
});

it('all PaymentIntentStatus labels are non-empty', function () {
    foreach (PaymentIntentStatus::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

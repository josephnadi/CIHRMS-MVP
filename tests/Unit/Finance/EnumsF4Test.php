<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;

it('PaymentIntentStatus exposes the F4 lifecycle cases (F4-R appends Refunded)', function () {
    $values = array_map(fn ($c) => $c->value, PaymentIntentStatus::cases());
    // F4 baseline: 6 cases. F4-R appends 'refunded'. Assert the F4 cases are still
    // present without pinning the total count, so future additions don't break this.
    foreach (['created', 'pending', 'success', 'failed', 'abandoned', 'expired'] as $required) {
        expect($values)->toContain($required);
    }
});

it('all PaymentIntentStatus labels are non-empty', function () {
    foreach (PaymentIntentStatus::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

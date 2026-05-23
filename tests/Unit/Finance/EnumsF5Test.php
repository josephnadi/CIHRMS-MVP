<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;

it('JournalSourceType includes BankAdjustment case for F5', function () {
    $values = array_map(fn ($c) => $c->value, JournalSourceType::cases());
    expect($values)->toContain('bank_adjustment');
});

it('JournalSourceType::BankAdjustment has a non-empty label', function () {
    expect(JournalSourceType::BankAdjustment->label())->toBeString()->not->toBeEmpty();
});

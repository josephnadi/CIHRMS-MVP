<?php

use App\Enums\GlAccountType;
use App\Enums\OrgBankAccountPurpose;

it('exposes all GL account types', function () {
    $values = array_map(fn ($c) => $c->value, GlAccountType::cases());
    expect($values)->toEqualCanonicalizing(['asset', 'liability', 'equity', 'income', 'expense']);
});

it('exposes all org bank account purposes', function () {
    $values = array_map(fn ($c) => $c->value, OrgBankAccountPurpose::cases());
    expect($values)->toEqualCanonicalizing(['operating', 'payroll', 'statutory_escrow', 'receipts', 'reserve']);
});

it('GL account type labels are non-empty', function () {
    foreach (GlAccountType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

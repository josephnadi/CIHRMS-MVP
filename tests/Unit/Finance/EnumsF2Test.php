<?php

declare(strict_types=1);

use App\Enums\ApPaymentStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\VendorInvoiceStatus;
use App\Enums\VendorStatus;

it('VendorStatus exposes active/inactive/suspended', function () {
    $values = array_map(fn ($c) => $c->value, VendorStatus::cases());
    expect($values)->toEqualCanonicalizing(['active', 'inactive', 'suspended']);
});

it('VendorInvoiceStatus exposes the full lifecycle', function () {
    $values = array_map(fn ($c) => $c->value, VendorInvoiceStatus::cases());
    expect($values)->toEqualCanonicalizing([
        'draft', 'pending_approval', 'approved', 'partially_paid', 'paid', 'cancelled',
    ]);
});

it('ApPaymentStatus exposes pending/processed/voided', function () {
    $values = array_map(fn ($c) => $c->value, ApPaymentStatus::cases());
    expect($values)->toEqualCanonicalizing(['pending', 'processed', 'voided']);
});

it('JournalEntryStatus exposes draft/posted/reversed', function () {
    $values = array_map(fn ($c) => $c->value, JournalEntryStatus::cases());
    expect($values)->toEqualCanonicalizing(['draft', 'posted', 'reversed']);
});

it('JournalSourceType exposes manual + invoice + payment sources', function () {
    $values = array_map(fn ($c) => $c->value, JournalSourceType::cases());
    // F2 added: manual, vendor_invoice, ap_payment. F3 extended with ar_invoice, ar_receipt. F5 added bank_adjustment.
    expect($values)->toEqualCanonicalizing([
        'manual', 'vendor_invoice', 'ap_payment', 'ar_invoice', 'ar_receipt', 'bank_adjustment',
    ]);
});

it('all F2 enum labels are non-empty', function () {
    foreach ([VendorStatus::cases(), VendorInvoiceStatus::cases(), ApPaymentStatus::cases(), JournalEntryStatus::cases(), JournalSourceType::cases()] as $enumCases) {
        foreach ($enumCases as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
    }
});

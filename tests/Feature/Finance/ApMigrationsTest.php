<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the vendor_invoices table', function () {
    expect(Schema::hasTable('vendor_invoices'))->toBeTrue();
    expect(Schema::hasColumns('vendor_invoices', [
        'id', 'reference', 'vendor_id', 'vendor_invoice_no', 'status',
        'invoice_date', 'due_date', 'subtotal', 'tax_amount', 'total', 'amount_paid',
        'currency', 'ap_gl_account_id', 'notes', 'accrual_journal_entry_id',
        'created_by', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the vendor_invoice_lines table', function () {
    expect(Schema::hasTable('vendor_invoice_lines'))->toBeTrue();
    expect(Schema::hasColumns('vendor_invoice_lines', [
        'id', 'vendor_invoice_id', 'line_no', 'description',
        'quantity', 'unit_price', 'line_total', 'tax_rate', 'tax_amount', 'gl_account_id',
    ]))->toBeTrue();
});

it('creates the ap_payments table', function () {
    expect(Schema::hasTable('ap_payments'))->toBeTrue();
    expect(Schema::hasColumns('ap_payments', [
        'id', 'reference', 'vendor_id', 'status', 'payment_date', 'amount', 'currency',
        'org_bank_account_id', 'narration', 'journal_entry_id', 'disbursement_id',
        'created_by', 'processed_by', 'processed_at', 'voided_by', 'voided_at',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the ap_payment_invoice_allocations table', function () {
    expect(Schema::hasTable('ap_payment_invoice_allocations'))->toBeTrue();
    expect(Schema::hasColumns('ap_payment_invoice_allocations', [
        'id', 'ap_payment_id', 'vendor_invoice_id', 'allocated_amount', 'notes',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

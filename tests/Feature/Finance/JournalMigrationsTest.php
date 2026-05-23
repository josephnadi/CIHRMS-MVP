<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the vendors table', function () {
    expect(Schema::hasTable('vendors'))->toBeTrue();
    expect(Schema::hasColumns('vendors', [
        'id', 'code', 'name', 'tax_id', 'status', 'email', 'phone', 'address',
        'default_expense_gl_account_id', 'default_ap_gl_account_id', 'default_bank_account_id',
        'notes', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the journal_entries table', function () {
    expect(Schema::hasTable('journal_entries'))->toBeTrue();
    expect(Schema::hasColumns('journal_entries', [
        'id', 'reference', 'entry_date', 'narration', 'status', 'source_type', 'source_id',
        'posted_at', 'posted_by', 'reversed_at', 'reversed_by', 'reversal_of_id', 'created_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the journal_lines table', function () {
    expect(Schema::hasTable('journal_lines'))->toBeTrue();
    expect(Schema::hasColumns('journal_lines', [
        'id', 'journal_entry_id', 'line_no', 'gl_account_id',
        'debit_amount', 'credit_amount', 'narration',
    ]))->toBeTrue();
});

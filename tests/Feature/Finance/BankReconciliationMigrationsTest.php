<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds external_ref column to ap_payments', function () {
    expect(Schema::hasColumn('ap_payments', 'external_ref'))->toBeTrue();
});

it('creates the bank_statements table', function () {
    expect(Schema::hasTable('bank_statements'))->toBeTrue();
    expect(Schema::hasColumns('bank_statements', [
        'id', 'org_bank_account_id', 'statement_date', 'period_start',
        'opening_balance', 'closing_balance', 'currency',
        'file_hash', 'file_name', 'format', 'imported_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the bank_statement_lines table', function () {
    expect(Schema::hasTable('bank_statement_lines'))->toBeTrue();
    expect(Schema::hasColumns('bank_statement_lines', [
        'id', 'bank_statement_id', 'line_no', 'transaction_date', 'value_date',
        'description', 'reference', 'amount', 'running_balance', 'line_hash',
        'matched_type', 'matched_id', 'confidence', 'reconciled_at',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates the bank_transaction_matches table', function () {
    expect(Schema::hasTable('bank_transaction_matches'))->toBeTrue();
    expect(Schema::hasColumns('bank_transaction_matches', [
        'id', 'bank_statement_line_id', 'matched_type', 'matched_id',
        'confidence', 'matched_by', 'matched_at',
        'unmatched_at', 'unmatched_by', 'unmatched_reason',
    ]))->toBeTrue();
});

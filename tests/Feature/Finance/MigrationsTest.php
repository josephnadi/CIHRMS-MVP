<?php

use Illuminate\Support\Facades\Schema;

it('creates the gl_accounts table', function () {
    expect(Schema::hasTable('gl_accounts'))->toBeTrue();
    expect(Schema::hasColumns('gl_accounts', [
        'id', 'code', 'name', 'type', 'parent_id',
        'is_active', 'currency', 'description',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the org_bank_accounts table', function () {
    expect(Schema::hasTable('org_bank_accounts'))->toBeTrue();
    expect(Schema::hasColumns('org_bank_accounts', [
        'id', 'gl_account_id', 'bank_name', 'branch', 'account_name',
        'account_number', 'sort_code', 'swift', 'currency', 'purpose',
        'opening_balance', 'is_active', 'notes',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the gl_account_balances table', function () {
    expect(Schema::hasTable('gl_account_balances'))->toBeTrue();
    expect(Schema::hasColumns('gl_account_balances', [
        'gl_account_id', 'balance', 'last_posted_at', 'updated_at',
    ]))->toBeTrue();
});

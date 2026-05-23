<?php

declare(strict_types=1);

use App\Models\ApPayment;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BankTransactionMatch;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
});

it('creates a bank statement with decimal balances + softDeletes', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date'      => '2026-05-31',
        'opening_balance'     => 1000.00,
        'closing_balance'     => 2500.50,
        'currency'            => 'GHS',
        'file_hash'           => str_repeat('a', 64),
        'file_name'           => 'sample.csv',
        'format'              => 'csv',
        'imported_by'         => $this->user->id,
    ]);

    expect((float) $stmt->opening_balance)->toBe(1000.00);
    expect((float) $stmt->closing_balance)->toBe(2500.50);
    expect($stmt->orgBankAccount->id)->toBe($this->bank->id);
    expect($stmt->deleted_at)->toBeNull();
});

it('bank_statements.file_hash is UNIQUE', function () {
    $hash = str_repeat('b', 64);
    BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => $hash, 'file_name' => 'a.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    expect(fn () => BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => $hash, 'file_name' => 'b.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('BankStatementLine.scopeUnreconciled filters reconciled_at IS NULL', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('c', 64), 'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 1, 'transaction_date' => '2026-05-30',
        'description' => 'unmatched', 'amount' => 50.00, 'line_hash' => str_repeat('1', 64),
    ]);
    BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 2, 'transaction_date' => '2026-05-30',
        'description' => 'matched', 'amount' => 75.00, 'line_hash' => str_repeat('2', 64),
        'matched_type' => 'X', 'matched_id' => 1, 'reconciled_at' => now(), 'confidence' => 'high',
    ]);

    expect(BankStatementLine::unreconciled()->pluck('line_no')->all())->toBe([1]);
});

it('BankStatementLine line_hash is UNIQUE within statement', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('d', 64), 'file_name' => 'y.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    $hash = str_repeat('e', 64);
    BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 1, 'transaction_date' => '2026-05-30',
        'description' => 'first', 'amount' => 50.00, 'line_hash' => $hash,
    ]);

    expect(fn () => BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 2, 'transaction_date' => '2026-05-30',
        'description' => 'dup', 'amount' => 50.00, 'line_hash' => $hash,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('BankTransactionMatch persists with matched_at + supports unmatch', function () {
    $stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31', 'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('f', 64), 'file_name' => 'z.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);
    $line = BankStatementLine::create([
        'bank_statement_id' => $stmt->id, 'line_no' => 1, 'transaction_date' => '2026-05-30',
        'description' => 'pay', 'amount' => -200.00, 'line_hash' => str_repeat('7', 64),
    ]);

    $match = BankTransactionMatch::create([
        'bank_statement_line_id' => $line->id,
        'matched_type' => 'App\\Models\\ApPayment',
        'matched_id'   => 1,
        'confidence'   => 'high',
        'matched_by'   => $this->user->id,
        'matched_at'   => now(),
    ]);

    expect($match->fresh()->matched_at)->not->toBeNull();
    expect($match->fresh()->unmatched_at)->toBeNull();

    $match->update(['unmatched_at' => now(), 'unmatched_by' => $this->user->id, 'unmatched_reason' => 'wrong']);
    expect($match->fresh()->unmatched_at)->not->toBeNull();
});

it('ApPayment.external_ref is fillable', function () {
    $vendor = Vendor::create(['code' => 'V1', 'name' => 'V', 'status' => 'active']);
    $pay = ApPayment::create([
        'reference'           => 'AP-X', 'vendor_id' => $vendor->id, 'payment_date' => '2026-05-30',
        'amount' => 100, 'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id, 'external_ref' => 'GCB-TX-9999',
    ]);

    expect($pay->external_ref)->toBe('GCB-TX-9999');
});

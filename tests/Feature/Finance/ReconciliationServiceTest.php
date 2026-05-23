<?php

declare(strict_types=1);

use App\Models\ApPayment;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BankTransactionMatch;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ReconciliationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
    $this->svc = app(ReconciliationService::class);

    $this->stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('s', 64),
        'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);

    $this->vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $this->pay = ApPayment::create([
        'reference' => 'AP-1', 'vendor_id' => $this->vendor->id,
        'payment_date' => '2026-05-05', 'amount' => 500,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);
    $this->line = BankStatementLine::create([
        'bank_statement_id' => $this->stmt->id, 'line_no' => 1,
        'transaction_date' => '2026-05-05',
        'description' => 'PAY V', 'reference' => 'BANK-REF-001',
        'amount' => -500.00, 'line_hash' => str_repeat('h', 64),
    ]);
});

it('link() updates the line, appends the match row, and back-populates external_ref', function () {
    $match = $this->svc->link($this->line, $this->pay, $this->user, 'manual');

    expect($this->line->fresh()->reconciled_at)->not->toBeNull();
    expect($this->line->fresh()->matched_id)->toBe($this->pay->id);
    expect($this->line->fresh()->confidence)->toBe('manual');

    expect($this->pay->fresh()->external_ref)->toBe('BANK-REF-001');

    expect($match)->toBeInstanceOf(BankTransactionMatch::class);
    expect(BankTransactionMatch::count())->toBe(1);
});

it('link() refuses an already-reconciled line', function () {
    $this->svc->link($this->line, $this->pay, $this->user, 'manual');

    expect(fn () => $this->svc->link($this->line->fresh(), $this->pay, $this->user, 'manual'))
        ->toThrow(\DomainException::class, 'already reconciled');
});

it('unlink() clears the line + stamps unmatched_at without deleting the audit row', function () {
    $this->svc->link($this->line, $this->pay, $this->user, 'manual');
    $this->svc->unlink($this->line->fresh(), $this->user, 'operator error');

    expect($this->line->fresh()->reconciled_at)->toBeNull();
    expect($this->line->fresh()->matched_id)->toBeNull();
    expect($this->line->fresh()->matched_type)->toBeNull();

    expect(BankTransactionMatch::count())->toBe(1);
    $match = BankTransactionMatch::first();
    expect($match->unmatched_at)->not->toBeNull();
    expect($match->unmatched_reason)->toBe('operator error');
});

it('unlink() does NOT clear back-populated external_ref', function () {
    $this->svc->link($this->line, $this->pay, $this->user, 'manual');
    $this->svc->unlink($this->line->fresh(), $this->user, 'operator error');

    expect($this->pay->fresh()->external_ref)->toBe('BANK-REF-001');
});

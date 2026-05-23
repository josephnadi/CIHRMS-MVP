<?php

declare(strict_types=1);

use App\Models\ApPayment;
use App\Models\ArReceipt;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Customer;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ReconciliationMatcher;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
    $this->matcher = app(ReconciliationMatcher::class);

    $this->stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('a', 64),
        'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);
});

function mkLine(int $stmtId, int $no, string $date, float $amount, string $desc, ?string $ref = null): BankStatementLine
{
    return BankStatementLine::create([
        'bank_statement_id' => $stmtId, 'line_no' => $no,
        'transaction_date' => $date,
        'description' => $desc, 'reference' => $ref,
        'amount' => $amount,
        'line_hash' => hash('sha256', "{$no}|{$amount}|{$desc}|{$ref}"),
    ]);
}

it('Tier 1: matches a credit line to AR receipt via external_ref', function () {
    $cust = Customer::create(['code' => 'C1', 'name' => 'C', 'status' => 'active']);
    $receipt = ArReceipt::create([
        'reference' => 'AR-X', 'customer_id' => $cust->id,
        'receipt_date' => '2026-05-10', 'amount' => 1500,
        'currency' => 'GHS', 'org_bank_account_id' => $this->bank->id,
        'external_ref' => 'PST-001',
        'status' => 'processed', 'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-10', 1500.00, 'PAYSTACK pst_ref', 'PST-001');

    $counts = $this->matcher->matchUnreconciled($this->stmt);

    expect($counts['high'])->toBe(1);
    expect($line->fresh()->matched_type)->toBe(ArReceipt::class);
    expect($line->fresh()->matched_id)->toBe($receipt->id);
    expect($line->fresh()->confidence)->toBe('high');
});

it('Tier 2: matches a debit line to AP payment via amount + date + reference-in-description', function () {
    $vendor = Vendor::create(['code' => 'V1', 'name' => 'V', 'status' => 'active']);
    $pay = ApPayment::create([
        'reference' => 'AP-2026-000001', 'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-05', 'amount' => 500,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-05', -500.00, 'SALARY ADV REF AP-2026-000001');

    $counts = $this->matcher->matchUnreconciled($this->stmt);

    expect($counts['medium'])->toBe(1);
    expect($line->fresh()->matched_id)->toBe($pay->id);
    expect($line->fresh()->confidence)->toBe('medium');
});

it('Tier 3 single candidate: amount + date match with no reference', function () {
    $vendor = Vendor::create(['code' => 'V2', 'name' => 'V', 'status' => 'active']);
    ApPayment::create([
        'reference' => 'AP-X', 'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-20', 'amount' => 49.50,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-20', -49.50, 'BANK CHARGES MAY');

    $counts = $this->matcher->matchUnreconciled($this->stmt);

    expect($counts['low'])->toBe(1);
    expect($line->fresh()->confidence)->toBe('low');
});

it('Tier 3 multi-candidate: leaves matched_id null but sets confidence=low', function () {
    $vendor = Vendor::create(['code' => 'V3', 'name' => 'V', 'status' => 'active']);
    for ($i = 1; $i <= 2; $i++) {
        ApPayment::create([
            'reference' => "AP-{$i}", 'vendor_id' => $vendor->id,
            'payment_date' => '2026-05-20', 'amount' => 100.00,
            'org_bank_account_id' => $this->bank->id,
            'created_by' => $this->user->id,
        ]);
    }

    $line = mkLine($this->stmt->id, 1, '2026-05-20', -100.00, 'PAYMENT');

    $this->matcher->matchUnreconciled($this->stmt);

    expect($line->fresh()->matched_id)->toBeNull();
    expect($line->fresh()->confidence)->toBe('low');
});

it('credit lines never match AP payments', function () {
    $vendor = Vendor::create(['code' => 'V4', 'name' => 'V', 'status' => 'active']);
    ApPayment::create([
        'reference' => 'AP-Y', 'vendor_id' => $vendor->id,
        'payment_date' => '2026-05-15', 'amount' => 300.00,
        'org_bank_account_id' => $this->bank->id,
        'created_by' => $this->user->id,
    ]);

    $line = mkLine($this->stmt->id, 1, '2026-05-15', 300.00, 'SOMETHING');

    $this->matcher->matchUnreconciled($this->stmt);

    expect($line->fresh()->matched_id)->toBeNull();
});

it('idempotent: re-running on the same statement leaves already-reconciled lines alone', function () {
    $cust = Customer::create(['code' => 'C2', 'name' => 'C', 'status' => 'active']);
    ArReceipt::create([
        'reference' => 'AR-Z', 'customer_id' => $cust->id,
        'receipt_date' => '2026-05-10', 'amount' => 200, 'currency' => 'GHS',
        'org_bank_account_id' => $this->bank->id, 'external_ref' => 'X',
        'status' => 'processed', 'created_by' => $this->user->id,
    ]);
    $line = mkLine($this->stmt->id, 1, '2026-05-10', 200.00, 'DEPOSIT', 'X');

    $this->matcher->matchUnreconciled($this->stmt);
    $firstReconciledAt = $line->fresh()->reconciled_at;

    $this->matcher->matchUnreconciled($this->stmt);

    expect($line->fresh()->reconciled_at?->toDateTimeString())->toBe($firstReconciledAt?->toDateTimeString());
});

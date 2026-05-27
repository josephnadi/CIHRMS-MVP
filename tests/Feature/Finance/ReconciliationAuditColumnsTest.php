<?php

use App\Models\ApPayment;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\ReconciliationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
});

it('writes matched_by + matched_at to the line itself (L9 audit trail redundancy)', function () {
    $bank = OrgBankAccount::active()->first();
    $stmt = BankStatement::create([
        'org_bank_account_id' => $bank->id,
        'statement_date'      => now()->toDateString(),
        'period_start'        => now()->subDays(7)->toDateString(),
        'opening_balance'     => 0,
        'closing_balance'     => 0,
        'currency'            => 'GHS',
        'file_hash'           => sha1('abc'),
        'file_name'           => 'sample.csv',
        'format'              => 'csv',
        'imported_by'         => User::factory()->create()->id,
    ]);
    $line = BankStatementLine::create([
        'bank_statement_id' => $stmt->id,
        'line_no'           => 1,
        'transaction_date'  => now()->toDateString(),
        'description'       => 'PAYEE A',
        'amount'            => -100.00,
        'running_balance'   => -100.00,
        'line_hash'         => sha1('l1'),
    ]);
    $matcher = User::factory()->create();
    $payment = ApPayment::create([
        'reference'           => 'PAY-TEST-001',
        'vendor_id'           => \App\Models\Vendor::factory()->create()->id,
        'payment_date'        => now()->toDateString(),
        'amount'              => 100.00,
        'currency'            => 'GHS',
        'org_bank_account_id' => $bank->id,
        'created_by'          => $matcher->id,
    ]);

    app(ReconciliationService::class)->link($line, $payment, $matcher, 'high');

    $line->refresh();
    expect($line->matched_by)->toBe($matcher->id);
    expect($line->matched_at)->not->toBeNull();
});

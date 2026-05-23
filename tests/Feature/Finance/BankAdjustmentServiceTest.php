<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\BankAdjustmentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->bank = OrgBankAccount::active()->first();
    $this->user = User::factory()->create();
    $this->svc = app(BankAdjustmentService::class);

    $this->stmt = BankStatement::create([
        'org_bank_account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'opening_balance' => 0, 'closing_balance' => 0,
        'file_hash' => str_repeat('z', 64),
        'file_name' => 'x.csv', 'format' => 'csv',
        'imported_by' => $this->user->id,
    ]);
});

function mkAdjLine(int $stmtId, float $amount, string $desc, int $no = 1): BankStatementLine
{
    return BankStatementLine::create([
        'bank_statement_id' => $stmtId, 'line_no' => $no,
        'transaction_date' => '2026-05-20',
        'description' => $desc, 'amount' => $amount,
        'line_hash' => hash('sha256', "{$no}|{$amount}|{$desc}"),
    ]);
}

it('posts a bank fee adjustment via JournalPostingService', function () {
    $line = mkAdjLine($this->stmt->id, -49.50, 'BANK CHARGES MAY');
    $expense = GlAccount::where('code', '5400')->first()
        ?? GlAccount::create(['code' => '5400', 'name' => 'Bank Charges', 'type' => 'expense']);
    GlAccountBalance::firstOrCreate(['gl_account_id' => $expense->id], ['balance' => 0]);

    $je = $this->svc->postAdjustment($line, $expense, $this->user, 'Bank fee May 2026');

    expect($je)->toBeInstanceOf(JournalEntry::class);
    expect($je->source_type)->toBe(JournalSourceType::BankAdjustment);
    expect($je->source_id)->toBe($line->id);

    expect($line->fresh()->matched_type)->toBe(JournalEntry::class);
    expect($line->fresh()->matched_id)->toBe($je->id);
    expect($line->fresh()->confidence)->toBe('manual');
});

it('posts an interest credit adjustment with reversed sign', function () {
    $line = mkAdjLine($this->stmt->id, 12.34, 'INTEREST EARNED');
    $income = GlAccount::where('code', '4900')->first()
        ?? GlAccount::create(['code' => '4900', 'name' => 'Other Income', 'type' => 'income']);
    GlAccountBalance::firstOrCreate(['gl_account_id' => $income->id], ['balance' => 0]);

    $je = $this->svc->postAdjustment($line, $income, $this->user, 'Interest May 2026');

    expect($je->lines->count())->toBe(2);
});

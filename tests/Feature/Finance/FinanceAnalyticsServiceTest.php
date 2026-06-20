<?php

declare(strict_types=1);

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\FinanceAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    $this->svc = app(FinanceAnalyticsService::class);

    // Org operating bank linked to GL 1100 so it counts as a cash account
    // (mirrors the canonical CashFlowReport cash definition).
    OrgBankAccount::create([
        'gl_account_id'  => GlAccount::where('code', '1100')->value('id'),
        'bank_name'      => 'GCB', 'account_name' => 'Operating', 'account_number' => '0001',
        'currency'       => 'GHS', 'purpose' => 'operating', 'opening_balance' => 0, 'is_active' => true,
    ]);

    // One posted JE: DR Bank 1100 5000 / CR Membership Dues 4100 5000 (income), in March.
    $je = JournalEntry::create([
        'reference' => 'JE-AN-1', 'entry_date' => '2026-03-10', 'narration' => 'income',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 5000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '4100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 5000]);

    // An expense JE: DR Operations 5200 2000 / CR Bank 1100 2000, in April.
    $je2 = JournalEntry::create([
        'reference' => 'JE-AN-2', 'entry_date' => '2026-04-12', 'narration' => 'expense',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je2->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '5200')->value('id'), 'debit_amount' => 2000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je2->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 2000]);
});

it('computes KPIs over the year-to-date window', function () {
    $from = CarbonImmutable::create(2026, 1, 1);
    $to   = CarbonImmutable::create(2026, 12, 31);
    $k = $this->svc->kpis($from, $to);

    expect($k['income_ytd'])->toEqualWithDelta(5000.0, 0.01)
        ->and($k['expenditure_ytd'])->toEqualWithDelta(2000.0, 0.01)
        ->and($k['surplus_ytd'])->toEqualWithDelta(3000.0, 0.01)
        ->and($k['cash_position'])->toEqualWithDelta(3000.0, 0.01); // 1100: +5000 -2000
});

it('builds monthly trend series across the range', function () {
    $from = CarbonImmutable::create(2026, 1, 1);
    $to   = CarbonImmutable::create(2026, 6, 30);
    $t = $this->svc->trends($from, $to);

    expect($t['months'])->toHaveCount(6)
        ->and($t['months'][0])->toBe('2026-01')
        ->and($t['income'][2])->toEqualWithDelta(5000.0, 0.01)      // March income
        ->and($t['expenditure'][3])->toEqualWithDelta(2000.0, 0.01) // April expense
        ->and($t['surplus'][2])->toEqualWithDelta(5000.0, 0.01)
        ->and($t['cash'][2])->toEqualWithDelta(5000.0, 0.01)        // cash asOf end of March = +5000
        ->and($t['cash'][3])->toEqualWithDelta(3000.0, 0.01);       // asOf end of April = +5000 -2000
    expect(collect($t['top_expenses'])->firstWhere('code', '5200')['amount'])->toEqualWithDelta(2000.0, 0.01);
});

it('reports AR outstanding and aging', function () {
    $customer = Customer::create(['code' => 'CUST-AN-1', 'name' => 'Analytics Test Customer']);

    ArInvoice::create([
        'reference' => 'ARI-1', 'customer_id' => $customer->id, 'status' => 'approved',
        'invoice_date' => '2026-03-01', 'due_date' => now()->subDays(45)->toDateString(),
        'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000, 'amount_received' => 0,
        'ar_gl_account_id' => GlAccount::where('code', '1200')->value('id'),
        'created_by' => User::factory()->create()->id,
    ]);
    $k = $this->svc->kpis(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::now());
    expect($k['ar_outstanding'])->toEqualWithDelta(1000.0, 0.01);

    $aging = $this->svc->aging();
    expect($aging['ar']['d30'] + $aging['ar']['d60'])->toBeGreaterThan(0.0); // 45 days overdue lands in a bucket
});

<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\FinancialPositionReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function fp_post(array $lines, string $date): void
{
    $je = JournalEntry::create([
        'reference' => 'JE-FP-' . uniqid(), 'entry_date' => $date, 'narration' => 'fp',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    $no = 1;
    foreach ($lines as [$code, $debit, $credit]) {
        JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => $no++, 'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'debit_amount' => $debit, 'credit_amount' => $credit]);
    }
}

it('balances: assets = liabilities + equity + surplus', function () {
    // Receive 5000 membership income into bank, pay 2000 salaries from bank.
    fp_post([['1100', 5000, 0], ['4100', 0, 5000]], '2026-06-10'); // bank +5000, income 5000
    fp_post([['5100', 2000, 0], ['1100', 0, 2000]], '2026-06-12'); // expense 2000, bank -2000

    $report = app(FinancialPositionReport::class)->asOf(CarbonImmutable::create(2026, 6, 30));

    // Bank = 3000 (asset). Surplus = income 5000 - expense 2000 = 3000. No liabilities/equity.
    expect($report['assets']['total_current'])->toBe(3000.0)
        ->and($report['liabilities']['total_current'])->toBe(0.0)
        ->and($report['surplus_current'])->toBe(3000.0)
        ->and($report['total_funds_current'])->toBe(3000.0) // equity 0 + surplus 3000
        ->and($report['balanced_current'])->toBeTrue();      // assets 3000 = liab 0 + funds 3000
});

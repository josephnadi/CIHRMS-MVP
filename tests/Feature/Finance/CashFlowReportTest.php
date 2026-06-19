<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\CashFlowReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run(); // links bank GLs (1100/1110/1120) to org_bank_accounts
});

function cf_post(array $lines, string $date): void
{
    $je = JournalEntry::create([
        'reference' => 'JE-CF-' . uniqid(), 'entry_date' => $date, 'narration' => 'cf',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    $no = 1;
    foreach ($lines as [$code, $debit, $credit]) {
        JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => $no++, 'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'debit_amount' => $debit, 'credit_amount' => $credit]);
    }
}

it('reconciles direct and indirect to the actual net change in cash', function () {
    // Operating in: membership 5000 to bank. Operating out: salaries 2000 from bank.
    cf_post([['1100', 5000, 0], ['4100', 0, 5000]], '2026-06-05');
    cf_post([['5100', 2000, 0], ['1100', 0, 2000]], '2026-06-08');
    // Financing: 1000 fund injection into bank (contra equity 3100).
    cf_post([['1100', 1000, 0], ['3100', 0, 1000]], '2026-06-10');

    $report = app(CashFlowReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    // Anchor: 5000 - 2000 + 1000 = 4000 net cash increase.
    expect($report['net_change'])->toBe(4000.0)
        ->and($report['direct']['net'])->toBe(4000.0)
        ->and($report['indirect']['net'])->toBe(4000.0);

    // Direct categories: operating = 5000 - 2000 = 3000; financing = 1000; investing = 0.
    expect($report['direct']['operating'])->toBe(3000.0)
        ->and($report['direct']['financing'])->toBe(1000.0)
        ->and($report['direct']['investing'])->toBe(0.0);

    // Indirect: surplus = income 5000 - expense 2000 = 3000 (operating), financing 1000.
    expect($report['indirect']['surplus'])->toBe(3000.0)
        ->and($report['indirect']['operating'])->toBe(3000.0)
        ->and($report['indirect']['financing'])->toBe(1000.0);
});

it('classifies a staff-loan disbursement as an investing outflow', function () {
    // Disburse a 1200 staff loan from bank: DR Loans Receivable (1300) / CR Bank.
    cf_post([['1300', 1200, 0], ['1100', 0, 1200]], '2026-06-15');

    $report = app(CashFlowReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($report['net_change'])->toBe(-1200.0)
        ->and($report['direct']['investing'])->toBe(-1200.0)
        ->and($report['indirect']['investing'])->toBe(-1200.0)
        ->and($report['direct']['net'])->toBe($report['indirect']['net']);
});

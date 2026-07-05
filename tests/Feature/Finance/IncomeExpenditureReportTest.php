<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\IncomeExpenditureReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function ie_post(string $drCode, string $crCode, float $amount, string $date): void
{
    $je = JournalEntry::create([
        'reference' => 'JE-IE-' . uniqid(), 'entry_date' => $date, 'narration' => 'ie',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', $drCode)->value('id'), 'debit_amount' => $amount, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', $crCode)->value('id'), 'debit_amount' => 0, 'credit_amount' => $amount]);
}

it('computes income minus expenditure as surplus for the period', function () {
    // June 2026: income 5000 (DR cash/CR membership), expense 3000 (DR salaries/CR payable)
    ie_post('1100', '4100', 5000, '2026-06-10'); // income
    ie_post('5100', '2300', 3000, '2026-06-12'); // expenditure

    $report = app(IncomeExpenditureReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($report['income']['total_current'])->toBe(5000.0)
        ->and($report['expenditure']['total_current'])->toBe(3000.0)
        ->and($report['surplus_current'])->toBe(2000.0);
});

it('splits income into Operating and Other with a Net Operating Income subtotal (CIHRM layout)', function () {
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run(); // sets statement_section

    ie_post('1100', '4120', 7_000, '2026-06-05'); // operating income (PCP/student fees)
    ie_post('1100', '4610', 2_000, '2026-06-06'); // other income (graduation)
    ie_post('5700', '2300', 4_000, '2026-06-07'); // expenditure

    $r = app(IncomeExpenditureReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($r['operating_income']['total_current'])->toBe(7_000.0)
        ->and($r['expenditure']['total_current'])->toBe(4_000.0)
        ->and($r['net_operating_current'])->toBe(3_000.0)   // 7,000 − 4,000
        ->and($r['other_income']['total_current'])->toBe(2_000.0)
        ->and($r['surplus_current'])->toBe(5_000.0);        // 3,000 + 2,000
});

it('includes a prior-period comparative', function () {
    ie_post('1100', '4100', 5000, '2026-06-10'); // current (June)
    ie_post('1100', '4100', 1000, '2026-05-10'); // prior (May)

    $report = app(IncomeExpenditureReport::class)->forPeriod(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($report['income']['total_current'])->toBe(5000.0)
        ->and($report['income']['total_prior'])->toBe(1000.0)   // May, the preceding equal-length window
        ->and($report['surplus_prior'])->toBe(1000.0);
});

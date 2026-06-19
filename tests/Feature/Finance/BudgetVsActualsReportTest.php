<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Finance\Reports\BudgetVsActualsReport;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

/** Post a one-sided actual onto an account via a balanced manual JE (other leg = cash 1100). */
function postActual(string $code, float $amount, string $date): void
{
    $acc  = GlAccount::where('code', $code)->firstOrFail();
    $cash = GlAccount::where('code', '1100')->firstOrFail();
    $isDebitNatural = in_array($acc->type->value, ['asset', 'expense'], true);

    $je = JournalEntry::create([
        'reference' => 'JE-BVA-' . $code . '-' . $date, 'entry_date' => $date, 'narration' => 'bva',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => App\Models\User::factory()->create()->id,
    ]);
    // account leg
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $acc->id,
        'debit_amount' => $isDebitNatural ? $amount : 0, 'credit_amount' => $isDebitNatural ? 0 : $amount]);
    // balancing cash leg
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cash->id,
        'debit_amount' => $isDebitNatural ? 0 : $amount, 'credit_amount' => $isDebitNatural ? $amount : 0]);
}

function budgetYear(int $year, array $codeToAnnual): void
{
    $fy = FiscalYear::firstOrCreate(['year' => $year],
        ['status' => 'open', 'starts_on' => "$year-01-01", 'ends_on' => "$year-12-31"]);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'approved']);
    foreach ($codeToAnnual as $code => $annual) {
        BudgetLine::create(['budget_id' => $budget->id,
            'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'annual_amount' => $annual]);
    }
}

it('spreads the annual budget evenly and compares YTD actual', function () {
    budgetYear(2026, ['5100' => 120000]); // Salaries (expense): 10,000/month
    postActual('5100', 25000, '2026-03-15'); // spend 25k by end of Q1

    $report = app(BudgetVsActualsReport::class)->forYear(2026, 3); // as of period 3 (March)

    $row = collect($report['groups'])->firstWhere('type', 'expense')['rows'][0];
    expect($row['code'])->toBe('5100')
        ->and($row['annual_budget'])->toBe(120000.0)
        ->and($row['ytd_budget'])->toBe(30000.0)   // 120000/12 * 3
        ->and($row['ytd_actual'])->toBe(25000.0)
        ->and($row['variance'])->toBe(5000.0)       // 30000 - 25000, under budget
        ->and($row['favourable'])->toBeTrue();      // expense under budget = favourable
});

it('flags an over-spent expense as unfavourable and at-target income as favourable', function () {
    budgetYear(2026, ['5100' => 120000, '4100' => 60000]);
    postActual('5100', 130000, '2026-06-20'); // overspent for full year
    postActual('4100', 70000, '2026-06-20');  // income over target

    $report = app(BudgetVsActualsReport::class)->forYear(2026, 12);

    $exp = collect($report['groups'])->firstWhere('type', 'expense')['rows'][0];
    $inc = collect($report['groups'])->firstWhere('type', 'income')['rows'][0];

    expect($exp['variance'])->toBe(-10000.0)        // 120000 - 130000
        ->and($exp['favourable'])->toBeFalse()       // expense over budget = unfavourable
        ->and($inc['favourable'])->toBeTrue();       // income at/over target = favourable
});

it('includes an un-budgeted account that has actuals (budget 0, actual surfaces)', function () {
    budgetYear(2026, ['5100' => 120000]);
    postActual('5110', 4000, '2026-02-10'); // Allowances (expense) with NO budget line

    $report = app(BudgetVsActualsReport::class)->forYear(2026, 12);
    $rows = collect($report['groups'])->firstWhere('type', 'expense')['rows'];
    $row  = collect($rows)->firstWhere('code', '5110');

    expect($row)->not->toBeNull()
        ->and($row['annual_budget'])->toBe(0.0)
        ->and($row['ytd_actual'])->toBe(4000.0)
        ->and($row['favourable'])->toBeFalse();      // 0 budget, 4000 spent = unfavourable
});

it('reports zero everything and has_budget=false when no budget exists', function () {
    $report = app(BudgetVsActualsReport::class)->forYear(2030, 12);
    expect($report['has_budget'])->toBeFalse()
        ->and($report['totals']['annual_budget'])->toBe(0.0)
        ->and($report['groups'])->toBe([]);
});

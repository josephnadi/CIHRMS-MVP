<?php

declare(strict_types=1);

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\GlAccount;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('stores a budget with lines and casts status', function () {
    $fy = FiscalYear::create(['year' => 2026, 'status' => 'open', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'draft']);
    $line = BudgetLine::create(['budget_id' => $budget->id, 'gl_account_id' => GlAccount::where('code', '5100')->value('id'), 'annual_amount' => 120000]);

    expect($budget->fresh()->status)->toBe(BudgetStatus::Draft)
        ->and((float) $line->fresh()->annual_amount)->toBe(120000.0)
        ->and($budget->lines()->count())->toBe(1)
        ->and($budget->fiscalYear->year)->toBe(2026)
        ->and(BudgetStatus::Approved->label())->toBe('Approved');
});

it('enforces one budget per fiscal year and one line per account', function () {
    $fy = FiscalYear::create(['year' => 2027, 'status' => 'open', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'draft']);
    expect(fn () => Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'draft']))
        ->toThrow(Illuminate\Database\QueryException::class);

    $budget = Budget::where('fiscal_year_id', $fy->id)->first();
    $acc = GlAccount::where('code', '5100')->value('id');
    BudgetLine::create(['budget_id' => $budget->id, 'gl_account_id' => $acc, 'annual_amount' => 100]);
    expect(fn () => BudgetLine::create(['budget_id' => $budget->id, 'gl_account_id' => $acc, 'annual_amount' => 200]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

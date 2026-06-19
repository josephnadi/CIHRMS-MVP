<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();

    $fy = FiscalYear::firstOrCreate(['year' => 2026],
        ['status' => 'open', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'approved']);
    BudgetLine::create(['budget_id' => $budget->id,
        'gl_account_id' => GlAccount::where('code', '5100')->value('id'), 'annual_amount' => 120000]);
});

it('renders the budget vs actuals report for a finance_officer', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/budget-vs-actuals?year=2026&period=12')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/BudgetVsActuals')
            ->where('report.has_budget', true)
            ->where('year', 2026));
});

it('lets an auditor view it (finance.reports.view) but forbids an employee', function () {
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->get('/finance/reports/budget-vs-actuals?year=2026')->assertOk();
    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/finance/reports/budget-vs-actuals?year=2026')->assertForbidden();
});

it('exports budget vs actuals as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/budget-vs-actuals/export.csv?year=2026&period=12');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
});

it('exports budget vs actuals as PDF', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/budget-vs-actuals/export.pdf?year=2026&period=12');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');
});

it('passes over-budget alerts to the report page', function () {
    // Overspend 5100 well past its 120000 budget.
    $acc  = GlAccount::where('code', '5100')->firstOrFail();
    $cash = GlAccount::where('code', '1100')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-ALERT-1', 'entry_date' => '2026-05-01', 'narration' => 'over',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $acc->id, 'debit_amount' => 200000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 200000]);

    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/budget-vs-actuals?year=2026&period=12')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/BudgetVsActuals')
            ->where('alerts.0.code', '5100')
            ->where('alerts.0.variance', fn ($v) => (float) $v < 0));
});

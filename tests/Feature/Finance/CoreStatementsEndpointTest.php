<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();

    $je = JournalEntry::create([
        'reference' => 'JE-CS-1', 'entry_date' => '2026-06-10', 'narration' => 'cs',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 5000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '4100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 5000]);
});

it('renders the financial activities statement', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/financial-activities?from=2026-06-01&to=2026-06-30')
        ->assertOk()
        // Surplus math is unit-tested in IncomeExpenditureReportTest; here we verify
        // the page wires the report prop (avoids a brittle int/float JSON assertion).
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/FinancialActivities')
            ->where('report.surplus_current', fn ($v) => abs((float) $v - 5000.0) < 0.005));
});

it('renders the financial position statement (balanced)', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/financial-position?as_of=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/FinancialPosition')->where('report.balanced_current', true));
});

it('renders the account ledger drill-down', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $bank = GlAccount::where('code', '1100')->firstOrFail();
    $this->actingAs($u)->get("/finance/reports/account/{$bank->id}/ledger?to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/AccountLedger')->has('lines', 1));
});

it('forbids an employee from the statements', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/reports/financial-position')->assertForbidden();
});

it('exports financial activities as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/financial-activities/export.csv?from=2026-06-01&to=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
});

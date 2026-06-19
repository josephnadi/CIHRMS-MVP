<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $je = JournalEntry::create([
        'reference' => 'JE-CFE-1', 'entry_date' => '2026-06-05', 'narration' => 'cfe',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '1100')->value('id'), 'debit_amount' => 5000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '4100')->value('id'), 'debit_amount' => 0, 'credit_amount' => 5000]);
});

it('renders the cash flow statement with both methods reconciling', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/cash-flow?from=2026-06-01&to=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/CashFlow')
            ->where('report.net_change', fn ($v) => abs((float) $v - 5000.0) < 0.005)
            ->where('report.direct.net', fn ($v) => abs((float) $v - 5000.0) < 0.005)
            ->where('report.indirect.net', fn ($v) => abs((float) $v - 5000.0) < 0.005));
});

it('forbids an employee from the cash flow statement', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/reports/cash-flow')->assertForbidden();
});

it('exports the cash flow statement as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/cash-flow/export.csv?from=2026-06-01&to=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
});

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
        'reference' => 'JE-EP-1', 'entry_date' => '2026-06-15', 'narration' => 'ep',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => GlAccount::where('code', '5100')->value('id'), 'debit_amount' => 1000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => GlAccount::where('code', '2300')->value('id'), 'debit_amount' => 0, 'credit_amount' => 1000]);
});

it('finance_officer can view the trial balance page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/trial-balance?as_of=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reports/TrialBalance')->where('report.balanced', true));
});

it('employee is forbidden from the trial balance', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/reports/trial-balance')->assertForbidden();
});

it('exports the trial balance as CSV', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/trial-balance/export.csv?as_of=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    $body = $res->streamedContent();
    expect($body)->toContain('5100')->toContain('1000');
});

it('exports the trial balance as PDF', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $res = $this->actingAs($u)->get('/finance/reports/trial-balance/export.pdf?as_of=2026-06-30');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');
});

it('falls back to today on a malformed as_of param (no 500)', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/reports/trial-balance?as_of=not-a-date')->assertOk();
});

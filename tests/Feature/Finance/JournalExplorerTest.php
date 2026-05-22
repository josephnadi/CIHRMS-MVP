<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('finance_officer can list and view journal entries', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/journal')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Journal/Index'));
});

it('auditor can view (journal.view permission)', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($u)->get('/finance/journal')->assertOk();
});

it('employee gets 403', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/journal')->assertForbidden();
});

it('finance_officer cannot post manual JE (no journal.post_manual)', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->post('/finance/journal', [])->assertForbidden();
});

it('super_admin can post a manual JE', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    $exp = \App\Models\GlAccount::where('code', '5200')->firstOrFail();
    $ap  = \App\Models\GlAccount::where('code', '2100')->firstOrFail();

    $this->actingAs($u)->post('/finance/journal', [
        'entry_date' => '2026-05-22',
        'narration'  => 'Manual test',
        'lines' => [
            ['gl_account_id' => $exp->id, 'debit_amount' => 50, 'credit_amount' => 0, 'narration' => 'Dr'],
            ['gl_account_id' => $ap->id,  'debit_amount' => 0,  'credit_amount' => 50, 'narration' => 'Cr'],
        ],
    ])->assertRedirect();

    expect(\App\Models\JournalEntry::where('source_type', 'manual')->count())->toBe(1);
});

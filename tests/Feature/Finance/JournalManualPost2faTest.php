<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

function journalManual2fa(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

it('manual journal.store creates nothing without fresh 2FA', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    $exp = GlAccount::where('code', '5200')->firstOrFail();
    $ap  = GlAccount::where('code', '2100')->firstOrFail();

    // Without fresh 2FA, the middleware bounces before the controller runs.
    $this->actingAs($u)->post('/finance/journal', [
        'entry_date' => '2026-05-22',
        'narration'  => 'should be blocked',
        'lines' => [
            ['gl_account_id' => $exp->id, 'debit_amount' => 50, 'credit_amount' => 0, 'narration' => 'Dr'],
            ['gl_account_id' => $ap->id,  'debit_amount' => 0,  'credit_amount' => 50, 'narration' => 'Cr'],
        ],
    ]);

    expect(\App\Models\JournalEntry::where('narration', 'should be blocked')->count())->toBe(0);
});

it('manual journal.store posts when 2FA is fresh', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    $exp = GlAccount::where('code', '5200')->firstOrFail();
    $ap  = GlAccount::where('code', '2100')->firstOrFail();

    $this->actingAs(journalManual2fa($u))->post('/finance/journal', [
        'entry_date' => '2026-05-22',
        'narration'  => 'fresh 2FA path',
        'lines' => [
            ['gl_account_id' => $exp->id, 'debit_amount' => 50, 'credit_amount' => 0, 'narration' => 'Dr'],
            ['gl_account_id' => $ap->id,  'debit_amount' => 0,  'credit_amount' => 50, 'narration' => 'Cr'],
        ],
    ])->assertRedirect();

    expect(\App\Models\JournalEntry::where('narration', 'fresh 2FA path')->count())->toBe(1);
});

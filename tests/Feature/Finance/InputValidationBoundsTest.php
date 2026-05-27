<?php

use App\Models\User;

it('rejects a reports export with year out of range (M13)', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);
    $this->actingAs($u)
        ->get('/reports/export?type=leave&year=999999999')
        ->assertSessionHasErrors('year');
});

it('rejects a reports export with month in the wrong format (M13)', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);
    $this->actingAs($u)
        ->get('/reports/export?type=payroll&month=not-a-month')
        ->assertSessionHasErrors('month');
});

it('rejects a JE line with both debit and credit equal to zero (L8)', function () {
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    $glA = \App\Models\GlAccount::first();
    $glB = \App\Models\GlAccount::skip(1)->first();

    (new \Database\Seeders\RolePermissionSeeder())->run();
    // Bypass the global 2FA gate: factory-default new users don't have
    // `two_factor_confirmed_at` and super_admin role flips `two_factor_required`.
    $u = User::factory()->create([
        'role'                    => 'super_admin',
        'permissions'             => ['*'],
        'two_factor_required'     => false,
        'two_factor_confirmed_at' => now(),
    ]);
    // Route requires fresh 2FA challenge — mark it fresh so we can exercise
    // the actual validation we want to test.
    app(\App\Services\Auth\TwoFactorService::class)->markFresh($u);
    $resp = $this->actingAs($u)
        ->from('/finance/journal')
        ->post('/finance/journal', [
            'entry_date' => now()->toDateString(),
            'lines'      => [
                ['gl_account_id' => $glA->id, 'debit_amount' => 0, 'credit_amount' => 0],
                ['gl_account_id' => $glB->id, 'debit_amount' => 0, 'credit_amount' => 0],
            ],
        ]);
    $resp->assertSessionHasErrors('lines.0.debit_amount');
});

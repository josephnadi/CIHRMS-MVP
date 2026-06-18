<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Services\Finance\SubledgerReconciliationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

function glBalanceForCode(string $code, float $balance): void
{
    $id = GlAccount::where('code', $code)->value('id');
    GlAccountBalance::where('gl_account_id', $id)->update(['balance' => $balance]);
}

it('reports all subledgers in balance when nothing is outstanding and GL is zero', function () {
    $rows = app(SubledgerReconciliationService::class)->reconcile();

    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row['variance'])->toBe(0.0)
            ->and($row['in_balance'])->toBeTrue();
    }
    expect(app(SubledgerReconciliationService::class)->hasVariance())->toBeFalse();
});

it('detects a variance when a GL control balance diverges from its subledger', function () {
    // No subledger entries (AP subledger = 0), but GL 2100 carries 500 → out of balance.
    glBalanceForCode('2100', 500.0);

    $svc = app(SubledgerReconciliationService::class);
    $rows = collect($svc->reconcile())->keyBy('gl_code');

    expect((float) $rows['2100']['subledger_total'])->toBe(0.0)
        ->and((float) $rows['2100']['gl_balance'])->toBe(500.0)
        ->and((float) $rows['2100']['variance'])->toBe(-500.0)
        ->and($rows['2100']['in_balance'])->toBeFalse()
        ->and($svc->hasVariance())->toBeTrue();

    // The other two remain in balance.
    expect($rows['1200']['in_balance'])->toBeTrue()
        ->and($rows['1300']['in_balance'])->toBeTrue();
});

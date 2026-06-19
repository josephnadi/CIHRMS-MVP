<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Finance\SubledgerReconciliationService;
use App\Services\Offboarding\SettlementPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

require_once __DIR__ . '/../Offboarding/SettlementAccrualTest.php'; // reuse seedSettlementWithLoan + settlementGl helpers

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);
});

it('shows no 1300 variance after a settlement clears a loan (subledger drops with the GL)', function () {
    [$settlement] = seedSettlementWithLoan(['gross' => 10000]);

    // Before clearing: disburse-equivalent state isn't posted here, so seed GL via the accrual itself.
    app(SettlementPostingService::class)->postAccrual($settlement, User::factory()->create());

    $rows = collect(app(SubledgerReconciliationService::class)->reconcile());
    $loanRow = $rows->firstWhere('gl_code', '1300');

    // Subledger principal-outstanding (excludes Waived) and GL 1300 both reflect the cleared loan.
    expect($loanRow['in_balance'])->toBeTrue();
});

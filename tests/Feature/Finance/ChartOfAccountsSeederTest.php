<?php

declare(strict_types=1);

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\OrgBankAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

it('seeds at least 30 GL accounts with at least one per type', function () {
    (new ChartOfAccountsSeeder())->run();

    expect(GlAccount::count())->toBeGreaterThanOrEqual(30);

    foreach (GlAccountType::cases() as $type) {
        expect(GlAccount::ofType($type)->count())->toBeGreaterThanOrEqual(1);
    }
});

it('chart of accounts seeder is idempotent', function () {
    (new ChartOfAccountsSeeder())->run();
    $countAfterFirst = GlAccount::count();

    (new ChartOfAccountsSeeder())->run();
    $countAfterSecond = GlAccount::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('balance seeder creates one balance row per account at zero', function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    expect(GlAccountBalance::count())->toBe(GlAccount::count());
    expect((float) GlAccountBalance::sum('balance'))->toBe(0.0);
});

it('balance seeder is idempotent', function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $countAfterFirst = GlAccountBalance::count();

    (new GlAccountBalanceSeeder())->run();
    expect(GlAccountBalance::count())->toBe($countAfterFirst);
});

it('seeds 3 org bank accounts linked to asset GL accounts', function () {
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    expect(OrgBankAccount::count())->toBe(3);
    foreach (OrgBankAccount::with('glAccount')->get() as $bank) {
        expect($bank->glAccount->type)->toBe(GlAccountType::Asset);
    }
});

it('org bank account seeder is idempotent', function () {
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    $countAfterFirst = OrgBankAccount::count();

    (new OrgBankAccountSeeder())->run();
    expect(OrgBankAccount::count())->toBe($countAfterFirst);
});

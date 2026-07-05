<?php

declare(strict_types=1);

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CihrmChartOfAccountsSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new CihrmChartOfAccountsSeeder())->run();
});

it('seeds CIHRM operating-income accounts under Income', function () {
    foreach ([
        '4110' => 'Subscription - Members',
        '4120' => 'PCP Fees & Subscription - Students',
        '4130' => 'Fees from CPD Programme',
        '4150' => 'Corporate Membership',
    ] as $code => $name) {
        $acc = GlAccount::where('code', $code)->firstOrFail();
        expect($acc->name)->toBe($name)
            ->and($acc->type)->toBe(GlAccountType::Income)
            ->and($acc->parent_id)->toBe(GlAccount::where('code', '4000')->value('id'));
    }
});

it('seeds CIHRM expenditure lines under Expenses', function () {
    $expense = GlAccount::whereIn('code', ['5700', '5717', '5724', '5725'])->get();
    expect($expense)->toHaveCount(4);
    $expense->each(fn ($a) => expect($a->type)->toBe(GlAccountType::Expense));
    expect(GlAccount::where('code', '5724')->value('name'))->toBe('Staff Cost');
});

it('adds the SOFP asset, deferred-income and Member\'s Fund accounts', function () {
    expect(GlAccount::where('code', '1400')->value('name'))->toBe('Property, Plant & Equipment');
    expect(GlAccount::where('code', '1500')->value('name'))->toBe('Inventory');
    expect(GlAccount::where('code', '2400')->firstOrFail())
        ->type->toBe(GlAccountType::Liability);
    expect(GlAccount::where('code', '3400')->firstOrFail())
        ->name->toBe('Revaluation Reserve')
        ->type->toBe(GlAccountType::Equity);
});

it('is idempotent and preserves the structural control accounts', function () {
    (new CihrmChartOfAccountsSeeder())->run(); // second run — no duplicates

    expect(GlAccount::where('code', '4120')->count())->toBe(1);
    // Posting-critical accounts from the base seeder are untouched.
    expect(GlAccount::where('code', '1200')->value('name'))->toBe('Accounts Receivable');
    expect(GlAccount::where('code', '2100')->value('name'))->toBe('Accounts Payable');
    expect(GlAccount::where('code', '2210')->value('name'))->toBe('PAYE Payable');
});

<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Services\Finance\AccountResolver;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
});

it('exposes the FinalSettlement source type', function () {
    expect(JournalSourceType::FinalSettlement->value)->toBe('final_settlement')
        ->and(JournalSourceType::FinalSettlement->label())->toBe('Final Settlement');
});

it('seeds the per-component termination expense accounts', function () {
    foreach (['5130', '5131', '5132', '5133', '5134'] as $code) {
        $acc = GlAccount::where('code', $code)->first();
        expect($acc)->not->toBeNull()
            ->and($acc->type->value)->toBe('expense');
    }
});

it('maps the settlement posting slugs to the right accounts', function () {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve('settlement.gratuity_expense')->code)->toBe('5130')
        ->and($resolver->resolve('settlement.severance_expense')->code)->toBe('5131')
        ->and($resolver->resolve('settlement.leave_encashment_expense')->code)->toBe('5132')
        ->and($resolver->resolve('settlement.thirteenth_month_expense')->code)->toBe('5133')
        ->and($resolver->resolve('settlement.ex_gratia_expense')->code)->toBe('5134')
        ->and($resolver->resolve('settlement.paye_payable')->code)->toBe('2210')
        ->and($resolver->resolve('settlement.deductions_payable')->code)->toBe('2250')
        ->and($resolver->resolve('settlement.net_pay_payable')->code)->toBe('2300');
});

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

it('seeds the 5130 termination benefits expense account', function () {
    $acc = GlAccount::where('code', '5130')->first();
    expect($acc)->not->toBeNull()
        ->and($acc->type->value)->toBe('expense');
});

it('maps the settlement posting slugs to the right accounts', function () {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve('settlement.benefits_expense')->code)->toBe('5130')
        ->and($resolver->resolve('settlement.paye_payable')->code)->toBe('2210')
        ->and($resolver->resolve('settlement.deductions_payable')->code)->toBe('2250')
        ->and($resolver->resolve('settlement.net_pay_payable')->code)->toBe('2300');
});

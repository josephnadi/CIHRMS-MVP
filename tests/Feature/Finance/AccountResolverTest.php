<?php

declare(strict_types=1);

use App\Exceptions\Finance\MissingAccountMappingException;
use App\Models\GlAccount;
use App\Services\Finance\AccountResolver;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
});

it('resolves a mapped slug to its GL account', function () {
    $account = app(AccountResolver::class)->resolve('payroll.net_pay_payable');
    expect($account)->toBeInstanceOf(GlAccount::class)->and($account->code)->toBe('2300');
});

it('throws when the slug is not mapped', function () {
    expect(fn () => app(AccountResolver::class)->resolve('does.not.exist'))
        ->toThrow(MissingAccountMappingException::class);
});

it('throws when the mapped account is inactive', function () {
    GlAccount::where('code', '2300')->update(['is_active' => false]);
    expect(fn () => app(AccountResolver::class)->resolve('payroll.net_pay_payable'))
        ->toThrow(MissingAccountMappingException::class);
});

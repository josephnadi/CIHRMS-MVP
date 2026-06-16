<?php

declare(strict_types=1);

use App\Models\PostingAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
});

it('maps every seeded slug to an active GL account', function () {
    (new PostingAccountSeeder())->run();

    $rules = PostingAccount::with('glAccount')->get();
    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        expect($rule->glAccount)->not->toBeNull("slug {$rule->slug} has no GL account")
            ->and($rule->glAccount->is_active)->toBeTrue("slug {$rule->slug} maps to an inactive account");
    }
});

it('is idempotent and maps net pay payable to 2300', function () {
    (new PostingAccountSeeder())->run();
    (new PostingAccountSeeder())->run();

    $rule = PostingAccount::where('slug', 'payroll.net_pay_payable')->firstOrFail();
    expect(PostingAccount::where('slug', 'payroll.net_pay_payable')->count())->toBe(1)
        ->and($rule->glAccount->code)->toBe('2300')
        ->and($rule->locked)->toBeTrue();
});

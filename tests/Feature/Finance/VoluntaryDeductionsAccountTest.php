<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;

it('seeds Voluntary Deductions Payable and maps the payroll slug to it', function () {
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();

    $gl = GlAccount::where('code', '2250')->first();
    expect($gl)->not->toBeNull()
        ->and($gl->name)->toBe('Voluntary Deductions Payable')
        ->and($gl->type->value)->toBe('liability');

    $rule = PostingAccount::where('slug', 'payroll.voluntary_deductions_payable')->firstOrFail();
    expect($rule->glAccount->code)->toBe('2250');
});

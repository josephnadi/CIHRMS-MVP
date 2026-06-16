<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('stores a mapping and resolves its GL account relation', function () {
    $gl = GlAccount::where('code', '2300')->firstOrFail();

    $rule = PostingAccount::create([
        'slug'          => 'payroll.net_pay_payable',
        'gl_account_id' => $gl->id,
        'domain'        => 'payroll',
        'description'   => 'Net pay owed to staff',
        'locked'        => true,
    ]);

    expect($rule->fresh()->locked)->toBeTrue()
        ->and($rule->glAccount->code)->toBe('2300');
});

it('enforces a unique slug', function () {
    $gl = GlAccount::where('code', '2300')->firstOrFail();
    PostingAccount::create(['slug' => 'dup.slug', 'gl_account_id' => $gl->id, 'domain' => 'payroll']);

    expect(fn () => PostingAccount::create(['slug' => 'dup.slug', 'gl_account_id' => $gl->id, 'domain' => 'payroll']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

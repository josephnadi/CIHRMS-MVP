<?php

declare(strict_types=1);

use App\Models\GlAccount;
use Database\Seeders\ChartOfAccountsSeeder;

it('seeds the interest income and cash-in-transit control accounts', function () {
    (new ChartOfAccountsSeeder())->run();

    $interest = GlAccount::where('code', '4600')->first();
    expect($interest)->not->toBeNull()
        ->and($interest->name)->toBe('Interest Income')
        ->and($interest->type->value)->toBe('income');

    $transit = GlAccount::where('code', '1130')->first();
    expect($transit)->not->toBeNull()
        ->and($transit->name)->toBe('Cash in Transit')
        ->and($transit->type->value)->toBe('asset');
});

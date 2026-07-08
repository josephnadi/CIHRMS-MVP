<?php

declare(strict_types=1);

use App\Models\FeeGlMapping;
use App\Models\GlAccount;

beforeEach(function () {
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
});

it('seeds a clearing account and a mapping per fee code', function () {
    expect(GlAccount::where('code', '1131')->exists())->toBeTrue();

    $sub = FeeGlMapping::forCode('member.subscription');
    expect($sub)->not->toBeNull()
        ->and($sub->is_deferred)->toBeTrue()
        ->and((int) $sub->recognition_months)->toBe(12)
        ->and($sub->deferredAccount->code)->toBe('2400')
        ->and($sub->clearingAccount->code)->toBe('1131');

    $exam = FeeGlMapping::forCode('exam');
    expect($exam)->not->toBeNull()
        ->and($exam->is_deferred)->toBeFalse()
        ->and($exam->incomeAccount->type->value)->toBe('income');
});

it('returns null for an unknown or inactive fee code', function () {
    expect(FeeGlMapping::forCode('does.not.exist'))->toBeNull();

    FeeGlMapping::forCode('exam')->update(['is_active' => false]);
    expect(FeeGlMapping::forCode('exam'))->toBeNull();
});

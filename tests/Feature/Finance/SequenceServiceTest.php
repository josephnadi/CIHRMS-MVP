<?php

declare(strict_types=1);

use App\Services\Finance\SequenceService;
use Illuminate\Support\Facades\DB;

it('returns 1 for a brand-new key', function () {
    expect(app(SequenceService::class)->next('test_key:2026'))->toBe(1);
});

it('returns monotonic increments on repeated calls', function () {
    $svc = app(SequenceService::class);
    $values = collect(range(1, 5))->map(fn () => $svc->next('test_key:2026'))->all();
    expect($values)->toBe([1, 2, 3, 4, 5]);
});

it('continues from a pre-seeded value', function () {
    DB::table('finance_sequences')->insert([
        'key'           => 'seeded:2026',
        'current_value' => 42,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(app(SequenceService::class)->next('seeded:2026'))->toBe(43);
});

it('keeps keys isolated', function () {
    $svc = app(SequenceService::class);
    $svc->next('a:2026');
    $svc->next('a:2026');
    $svc->next('a:2026');
    expect($svc->next('b:2026'))->toBe(1);
    expect($svc->next('a:2026'))->toBe(4);
});

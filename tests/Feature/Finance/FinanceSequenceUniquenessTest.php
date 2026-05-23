<?php

declare(strict_types=1);

use App\Services\Finance\SequenceService;

it('SequenceService::next returns 50 distinct, monotonic values for a single key', function () {
    $svc = app(SequenceService::class);
    $values = [];
    for ($i = 0; $i < 50; $i++) {
        $values[] = $svc->next('stress:2026');
    }
    expect($values)->toHaveCount(50);
    expect(array_unique($values))->toHaveCount(50);
    expect($values)->toBe(range(1, 50));
});

it('different scope keys advance independently', function () {
    $svc = app(SequenceService::class);
    for ($i = 0; $i < 10; $i++) {
        $svc->next('alpha:2026');
    }
    for ($i = 0; $i < 3; $i++) {
        $svc->next('beta:2026');
    }
    expect($svc->next('alpha:2026'))->toBe(11);
    expect($svc->next('beta:2026'))->toBe(4);
});

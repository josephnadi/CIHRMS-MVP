<?php

use App\Http\Controllers\Finance\JournalController;

it('generates monotonic unique JM-YYYY-NNNNNN references via SequenceService', function () {
    $ctrl   = app(JournalController::class);
    $method = new ReflectionMethod($ctrl, 'nextManualRef');
    $method->setAccessible(true);

    $refs = collect(range(1, 25))->map(fn () => $method->invoke($ctrl))->all();

    expect(array_unique($refs))->toHaveCount(25);
    $year = now()->format('Y');
    expect($refs[0])->toBe("JM-{$year}-000001");
    expect($refs[24])->toBe("JM-{$year}-000025");
});

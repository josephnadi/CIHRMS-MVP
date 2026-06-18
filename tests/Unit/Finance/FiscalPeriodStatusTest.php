<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;

it('exposes open/closed/locked with labels', function () {
    expect(FiscalPeriodStatus::Open->value)->toBe('open')
        ->and(FiscalPeriodStatus::Closed->value)->toBe('closed')
        ->and(FiscalPeriodStatus::Locked->value)->toBe('locked')
        ->and(FiscalPeriodStatus::Open->label())->toBe('Open')
        ->and(FiscalPeriodStatus::Locked->label())->toBe('Locked');
});

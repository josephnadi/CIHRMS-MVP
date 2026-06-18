<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodCloseService;

beforeEach(function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $this->period = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
    $this->user = User::factory()->create();
});

it('closes an open period with actor attribution', function () {
    $svc = app(PeriodCloseService::class);
    $svc->close($this->period, $this->user);

    $fresh = $this->period->fresh();
    expect($fresh->status)->toBe(FiscalPeriodStatus::Closed)
        ->and($fresh->closed_by)->toBe($this->user->id)
        ->and($fresh->closed_at)->not->toBeNull();
});

it('reopens a closed period and clears close attribution', function () {
    $svc = app(PeriodCloseService::class);
    $svc->close($this->period, $this->user);
    $svc->reopen($this->period->fresh(), $this->user);

    $fresh = $this->period->fresh();
    expect($fresh->status)->toBe(FiscalPeriodStatus::Open)
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->closed_by)->toBeNull();
});

it('locks a closed period with actor attribution', function () {
    $svc = app(PeriodCloseService::class);
    $svc->close($this->period, $this->user);
    $svc->lock($this->period->fresh(), $this->user);

    $fresh = $this->period->fresh();
    expect($fresh->status)->toBe(FiscalPeriodStatus::Locked)
        ->and($fresh->locked_by)->toBe($this->user->id)
        ->and($fresh->locked_at)->not->toBeNull();
});

it('rejects invalid transitions', function () {
    $svc = app(PeriodCloseService::class);

    // cannot reopen an open period
    expect(fn () => $svc->reopen($this->period, $this->user))->toThrow(DomainException::class);
    // cannot lock an open period (must be closed first)
    expect(fn () => $svc->lock($this->period, $this->user))->toThrow(DomainException::class);

    // close, then lock, then verify a locked period cannot be reopened
    $svc->close($this->period, $this->user);
    $svc->lock($this->period->fresh(), $this->user);
    expect(fn () => $svc->reopen($this->period->fresh(), $this->user))->toThrow(DomainException::class);
});

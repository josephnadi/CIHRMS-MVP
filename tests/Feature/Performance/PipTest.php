<?php

use App\Enums\PipStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Performance\PipService;

beforeEach(function () {
    $dept = Department::factory()->create();
    $this->employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
    $this->opener   = User::factory()->create(['role' => 'hr_admin']);
    $this->svc      = app(PipService::class);

    $this->targetMetrics = [
        ['metric' => 'Calls handled per day',  'target' => 30],
        ['metric' => 'First-contact resolution', 'target' => 0.7],
    ];
});

it('opens a PIP with target metrics and a 90-day default duration', function () {
    $pip = $this->svc->open($this->employee, null, null, $this->targetMetrics, $this->opener);

    expect($pip->status)->toBe(PipStatus::Open);
    expect($pip->target_metrics)->toHaveCount(2);
    expect($pip->opened_on->diffInDays($pip->target_end_date))->toEqualWithDelta(90, 1);
});

it('refuses a second open PIP for the same employee', function () {
    $this->svc->open($this->employee, null, null, $this->targetMetrics, $this->opener);

    expect(fn () => $this->svc->open($this->employee, null, null, $this->targetMetrics, $this->opener))
        ->toThrow(\DomainException::class, 'already has an open PIP');
});

it('records check-ins and transitions to in_progress', function () {
    $pip = $this->svc->open($this->employee, null, null, $this->targetMetrics, $this->opener);

    $pip = $this->svc->addCheckin($pip, $this->opener, 'Week 1 — improving on call volume', false);

    expect($pip->status)->toBe(PipStatus::InProgress);
    expect($pip->checkins)->toHaveCount(1);
});

it('enforces extension limit', function () {
    $pip = $this->svc->open($this->employee, null, null, $this->targetMetrics, $this->opener);
    $this->svc->extend($pip, 30, $this->opener, 'Mentor unavailable for 2 weeks');

    expect(fn () => $this->svc->extend($pip->fresh(), 30, $this->opener, 'second extension'))
        ->toThrow(\DomainException::class, 'extension limit reached');
});

it('closes the PIP with an outcome and locks further changes', function () {
    $pip = $this->svc->open($this->employee, null, null, $this->targetMetrics, $this->opener);
    $pip = $this->svc->close($pip, PipStatus::Succeeded, $this->opener, 'Met all targets in last 2 check-ins.');

    expect($pip->status)->toBe(PipStatus::Succeeded);
    expect($pip->actual_end_date)->not->toBeNull();

    expect(fn () => $this->svc->addCheckin($pip->fresh(), $this->opener, 'after-close', true))
        ->toThrow(\DomainException::class, 'Cannot check in on a closed PIP');
});

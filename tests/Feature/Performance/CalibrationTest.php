<?php

use App\Enums\CalibrationStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Services\Performance\CalibrationService;

beforeEach(function () {
    $this->cycle = ReviewCycle::create([
        'name' => 'H1 2026', 'cadence' => 'half_year',
        'starts_at' => '2026-01-01', 'ends_at' => '2026-06-30',
        'status' => 'open',
    ]);

    $dept = Department::factory()->create();
    $this->employee = Employee::factory()->create(['department_id' => $dept->id]);
    $reviewer = User::factory()->create();

    $this->review = Review::create([
        'cycle_id' => $this->cycle->id, 'employee_id' => $this->employee->id,
        'reviewer_id' => $reviewer->id, 'type' => 'manager',
        'overall_rating' => 4.5, 'status' => 'submitted',
    ]);

    $this->facilitator = User::factory()->create(['role' => 'hr_admin']);
    $this->applier     = User::factory()->create(['role' => 'auditor']);

    $this->svc = app(CalibrationService::class);
});

it('opens a session with default distribution', function () {
    $session = $this->svc->open($this->cycle, null, $this->facilitator);

    expect($session->status)->toBe(CalibrationStatus::InProgress);
    expect($session->target_distribution)->toBe(CalibrationService::DEFAULT_DISTRIBUTION);
});

it('records an adjustment with the original rating snapshot', function () {
    $session = $this->svc->open($this->cycle, null, $this->facilitator);

    $adj = $this->svc->recordAdjustment($session, $this->review, 3.5, 'Calibrated down — too generous vs peers', $this->facilitator);

    expect((float) $adj->original_rating)->toBe(4.5);
    expect((float) $adj->adjusted_rating)->toBe(3.5);
    expect($adj->reason)->toContain('Calibrated');
});

it('enforces dual control on apply', function () {
    $session = $this->svc->open($this->cycle, null, $this->facilitator);
    $this->svc->recordAdjustment($session, $this->review, 3.5, null, $this->facilitator);
    $this->svc->lock($session, $this->facilitator);

    expect(fn () => $this->svc->apply($session->fresh(), $this->facilitator))
        ->toThrow(\DomainException::class, 'Dual-control');

    $applied = $this->svc->apply($session->fresh(), $this->applier);
    expect($applied->status)->toBe(CalibrationStatus::Applied);
});

it('writes adjusted ratings back to the Review on apply', function () {
    $session = $this->svc->open($this->cycle, null, $this->facilitator);
    $this->svc->recordAdjustment($session, $this->review, 3.0, 'down', $this->facilitator);
    $this->svc->lock($session, $this->facilitator);
    $this->svc->apply($session->fresh(), $this->applier);

    expect((float) $this->review->fresh()->overall_rating)->toBe(3.0);
});

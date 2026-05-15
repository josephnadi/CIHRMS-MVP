<?php

use App\Enums\GoalStatus;
use App\Enums\ReviewCycleStatus;
use App\Enums\ReviewStatus;
use App\Enums\ReviewType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;

beforeEach(function () {
    $this->dept = Department::factory()->create();
    $this->hr = User::factory()->create(['role' => 'hr_admin']);
    $this->employeeUser = User::factory()->create(['role' => 'employee']);
    $this->employee = Employee::factory()->active()->create([
        'user_id'       => $this->employeeUser->id,
        'department_id' => $this->dept->id,
    ]);
});

test('HR can create a review cycle', function () {
    $this->actingAs($this->hr)
        ->post(route('performance.cycles.store'), [
            'name'      => 'Q2 2026 Performance',
            'cadence'   => 'quarterly',
            'starts_at' => '2026-04-01',
            'ends_at'   => '2026-06-30',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('review_cycles', [
        'name'    => 'Q2 2026 Performance',
        'cadence' => 'quarterly',
        'status'  => ReviewCycleStatus::Draft->value,
    ]);
});

test('HR can close an active cycle', function () {
    $cycle = ReviewCycle::create([
        'name'      => 'Q1 2026',
        'cadence'   => 'quarterly',
        'starts_at' => '2026-01-01',
        'ends_at'   => '2026-03-31',
        'status'    => ReviewCycleStatus::Active->value,
        'opened_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->patch(route('performance.cycles.close', $cycle))
        ->assertRedirect();

    $cycle->refresh();
    expect($cycle->status->value)->toBe(ReviewCycleStatus::Closed->value);
    expect($cycle->closed_at)->not->toBeNull();
});

test('HR can create a goal for an employee', function () {
    $this->actingAs($this->hr)
        ->post(route('performance.goals.store'), [
            'employee_id'  => $this->employee->id,
            'title'        => 'Reduce ticket resolution time to under 4 hours',
            'cadence'      => 'quarterly',
            'target_value' => 4,
            'unit'         => 'hours',
            'starts_at'    => '2026-04-01',
            'due_at'       => '2026-06-30',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('goals', [
        'employee_id' => $this->employee->id,
        'title'       => 'Reduce ticket resolution time to under 4 hours',
        'status'      => GoalStatus::Active->value,
    ]);
});

test('check-in with amber mood auto-flips goal status to AtRisk', function () {
    $goal = Goal::create([
        'employee_id'  => $this->employee->id,
        'title'        => 'Hit quarterly target',
        'cadence'      => 'quarterly',
        'target_value' => 100,
        'current_value'=> 20,
        'status'       => GoalStatus::Active->value,
        'created_by'   => $this->hr->id,
    ]);

    $this->actingAs($this->employeeUser)
        ->post(route('performance.goals.checkins.store', $goal), [
            'progress_pct'  => 25,
            'current_value' => 25,
            'narrative'     => 'Falling behind due to staffing gap.',
            'mood'          => 'amber',
        ])
        ->assertRedirect();

    $goal->refresh();
    expect($goal->status->value)->toBe(GoalStatus::AtRisk->value);
    expect((float) $goal->current_value)->toBe(25.0);
    expect($goal->checkins()->count())->toBe(1);
});

test('green-mood check-in does not change goal status', function () {
    $goal = Goal::create([
        'employee_id'  => $this->employee->id,
        'title'        => 'On-track goal',
        'cadence'      => 'monthly',
        'status'       => GoalStatus::Active->value,
        'created_by'   => $this->hr->id,
    ]);

    $this->actingAs($this->employeeUser)
        ->post(route('performance.goals.checkins.store', $goal), [
            'progress_pct' => 65,
            'narrative'    => 'Tracking well.',
            'mood'         => 'green',
        ])
        ->assertRedirect();

    expect($goal->fresh()->status->value)->toBe(GoalStatus::Active->value);
});

test('employee can submit a self-review and then acknowledge it', function () {
    $cycle = ReviewCycle::create([
        'name'      => 'Q2 2026',
        'cadence'   => 'quarterly',
        'starts_at' => '2026-04-01',
        'ends_at'   => '2026-06-30',
        'status'    => ReviewCycleStatus::Active->value,
    ]);

    // Self-review: employee = self, reviewer = self
    $this->actingAs($this->employeeUser)
        ->post(route('performance.reviews.store'), [
            'cycle_id'           => $cycle->id,
            'employee_id'        => $this->employee->id,
            'reviewer_id'        => $this->employeeUser->id,
            'type'               => ReviewType::Self->value,
            'performance_rating' => 4.0,
            'potential_rating'   => 3.5,
            'strengths'          => 'Consistent delivery, clear comms.',
        ])
        ->assertRedirect();

    $review = Review::latest('id')->first();
    expect($review)->not->toBeNull();
    expect($review->status->value)->toBe(ReviewStatus::Draft->value);

    // Submit (self-review: reviewer can submit own draft)
    $this->actingAs($this->employeeUser)
        ->patch(route('performance.reviews.submit', $review))
        ->assertRedirect();

    $review->refresh();
    expect($review->status->value)->toBe(ReviewStatus::Submitted->value);
    expect($review->submitted_at)->not->toBeNull();

    // Acknowledge (subject is the same employee)
    $this->actingAs($this->employeeUser)
        ->patch(route('performance.reviews.ack', $review))
        ->assertRedirect();

    expect($review->fresh()->status->value)->toBe(ReviewStatus::Acknowledged->value);
});

test('9-box matrix returns empty cells when no submitted reviews exist', function () {
    $cycle = ReviewCycle::create([
        'name'      => 'Empty Cycle',
        'cadence'   => 'quarterly',
        'starts_at' => '2026-04-01',
        'ends_at'   => '2026-06-30',
        'status'    => ReviewCycleStatus::Active->value,
    ]);

    $response = $this->actingAs($this->hr)
        ->get(route('performance.nine-box', ['cycle_id' => $cycle->id]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('Performance/NineBox')
            ->where('matrix.total', 0)
            ->has('matrix.cells', 9)
        );
});

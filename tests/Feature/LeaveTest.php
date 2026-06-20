<?php

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
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

test('employee can submit a leave request', function () {
    $response = $this->actingAs($this->employeeUser)
        ->post(route('leave.store'), [
            'employee_id' => $this->employee->id,
            'start_date'  => now()->addWeek()->toDateString(),
            'end_date'    => now()->addWeek()->addDays(4)->toDateString(),
            'type'        => LeaveType::Annual->value,
            'reason'      => 'Family vacation',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('leave_requests', [
        'employee_id' => $this->employee->id,
        'type'        => LeaveType::Annual->value,
        'status'      => LeaveStatus::Pending->value,
    ]);
});

test('employee can submit a leave request without sending employee_id (self-service)', function () {
    // The apply form does not send employee_id; it must default to the actor's own employee.
    $response = $this->actingAs($this->employeeUser)
        ->post(route('leave.store'), [
            'start_date' => now()->addWeek()->toDateString(),
            'end_date'   => now()->addWeek()->addDays(2)->toDateString(),
            'type'       => LeaveType::Annual->value,
            'reason'     => 'Personal',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('leave_requests', [
        'employee_id' => $this->employee->id,
        'type'        => LeaveType::Annual->value,
    ]);
});

test('approving a leave request stamps approver and increments balance', function () {
    $leave = LeaveRequest::factory()->pending()->create([
        'employee_id' => $this->employee->id,
        'start_date'  => '2026-06-01',
        'end_date'    => '2026-06-05',
        'type'        => LeaveType::Annual->value,
    ]);

    $this->actingAs($this->hr)
        ->patch(route('leave.update', $leave), [
            'status' => LeaveStatus::Approved->value,
        ])
        ->assertRedirect();

    $leave->refresh();
    expect($leave->status->value)->toBe(LeaveStatus::Approved->value);
    expect($leave->approved_by)->toBe($this->hr->id);

    $balance = LeaveBalance::where('employee_id', $this->employee->id)
        ->where('type', LeaveType::Annual->value)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull();
    // 4-day duration (Jun 1 → Jun 5, inclusive of weekends depends on durationInDays)
    expect((float) $balance->used_days)->toBeGreaterThan(0);
});

test('rejecting a leave request does not create a balance', function () {
    $leave = LeaveRequest::factory()->pending()->create([
        'employee_id' => $this->employee->id,
        'type'        => LeaveType::Annual->value,
    ]);

    $this->actingAs($this->hr)
        ->patch(route('leave.update', $leave), [
            'status' => LeaveStatus::Rejected->value,
        ])
        ->assertRedirect();

    $leave->refresh();
    expect($leave->status->value)->toBe(LeaveStatus::Rejected->value);
    expect($leave->approved_by)->toBeNull();
    expect(LeaveBalance::where('employee_id', $this->employee->id)->count())->toBe(0);
});

test('leave index renders inertia view with paginated leaves', function () {
    LeaveRequest::factory()->count(3)->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->hr)
        ->get(route('leave.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Leave/Index')
            ->has('leaves.data', 3)
        );
});

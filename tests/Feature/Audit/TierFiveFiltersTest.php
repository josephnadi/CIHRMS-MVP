<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\AttendanceSummary;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PerformanceContract;
use App\Models\ReviewCycle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/*
|--------------------------------------------------------------------------
| Tier-5 unwired filters & dead props
|--------------------------------------------------------------------------
|
| Audit V2 — Tier 5 wired three filter inputs that were declared in Vue but
| ignored by their controllers (so URL state never round-tripped) and pruned
| one dead prop. Each test below proves the wire works end-to-end.
|
| Covered:
|   T5.1  Performance Contracts /performance-contracts?search=NEEDLE
|   T5.2  Attendance /attendance?status=…&q=…
|   T5.3  Leave /leave pendingCount populated for approvers, 0 for self-service
|
*/

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

// ── T5.1 ─────────────────────────────────────────────────────────────────────

it('Performance Contracts index filters by ?search against employee name and employee_no', function () {
    $dept = Department::factory()->create();

    $aliceUser = User::factory()->create(['name' => 'Alice Mensah', 'role' => 'employee']);
    $alice     = Employee::factory()->create([
        'department_id' => $dept->id, 'user_id' => $aliceUser->id,
        'employee_no'   => 'EMP-A001', 'status' => 'active',
    ]);

    $bobUser = User::factory()->create(['name' => 'Bob Kwame', 'role' => 'employee']);
    $bob     = Employee::factory()->create([
        'department_id' => $dept->id, 'user_id' => $bobUser->id,
        'employee_no'   => 'EMP-B002', 'status' => 'active',
    ]);

    $cycle = ReviewCycle::create([
        'name'      => 'H1 2026', 'cadence' => 'half_year',
        'starts_at' => '2026-01-01', 'ends_at' => '2026-06-30',
        'status'    => 'active',
    ]);

    // Two contracts — only one should match the search needle.
    PerformanceContract::create([
        'cycle_id'    => $cycle->id, 'employee_id' => $alice->id,
        'status'      => 'draft', 'kpis' => [],
    ]);
    PerformanceContract::create([
        'cycle_id'    => $cycle->id, 'employee_id' => $bob->id,
        'status'      => 'draft', 'kpis' => [],
    ]);

    // super_admin gets the '*' wildcard, satisfying the viewAny policy.
    $admin = User::factory()->create(['role' => 'super_admin']);

    // Case-insensitive search on the employee name "Alice".
    $this->actingAs($admin)
        ->get(route('performance.contracts.index', ['search' => 'alice']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Performance/Contracts/Index')
            ->has('contracts.data', 1)
            ->where('contracts.data.0.employee.employee_no', 'EMP-A001')
            ->where('filters.search', 'alice')
        );

    // Search by employee_no fragment also works.
    $this->actingAs($admin)
        ->get(route('performance.contracts.index', ['search' => 'B002']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('contracts.data', 1)
            ->where('contracts.data.0.employee.employee_no', 'EMP-B002')
        );
});

// ── T5.2 ─────────────────────────────────────────────────────────────────────

it('Attendance index round-trips ?status and ?q via the filters payload', function () {
    $dept = Department::factory()->create();
    $hr   = User::factory()->create(['role' => 'hr_admin']);

    $aliceUser = User::factory()->create(['name' => 'Alice Mensah', 'role' => 'employee']);
    $alice = Employee::factory()->active()->create([
        'department_id' => $dept->id, 'user_id' => $aliceUser->id,
        'employee_no'   => 'ATT-A001',
    ]);

    $bobUser = User::factory()->create(['name' => 'Bob Kwame', 'role' => 'employee']);
    $bob = Employee::factory()->active()->create([
        'department_id' => $dept->id, 'user_id' => $bobUser->id,
        'employee_no'   => 'ATT-B002',
    ]);

    $today = now()->startOfDay();

    AttendanceSummary::create([
        'employee_id'  => $alice->id,
        'summary_date' => $today,
        'status'       => AttendanceStatus::Present->value,
        'hours_worked' => 8, 'overtime_hours' => 0,
    ]);
    AttendanceSummary::create([
        'employee_id'  => $bob->id,
        'summary_date' => $today,
        'status'       => AttendanceStatus::Absent->value,
        'hours_worked' => 0, 'overtime_hours' => 0,
    ]);

    $month = $today->format('Y-m');

    // ── status filter narrows to absent rows only ─────────────────────────
    $this->actingAs($hr)
        ->get(route('attendance.index', ['month' => $month, 'status' => 'absent']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Attendance/Index')
            ->has('summaries.data', 1)
            ->where('summaries.data.0.status', 'absent')
            ->where('filters.status', 'absent')
        );

    // ── q filter narrows to Alice ─────────────────────────────────────────
    $this->actingAs($hr)
        ->get(route('attendance.index', ['month' => $month, 'q' => 'alice']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('summaries.data', 1)
            ->where('filters.q', 'alice')
        );

    // ── q filter by employee_no fragment ──────────────────────────────────
    $this->actingAs($hr)
        ->get(route('attendance.index', ['month' => $month, 'q' => 'B002']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('summaries.data', 1)
            ->where('filters.q', 'B002')
        );
});

// ── T5.3 ─────────────────────────────────────────────────────────────────────

it('Leave index populates pendingCount for an approver and 0 for a self-service user', function () {
    $dept = Department::factory()->create();

    $hr = User::factory()->create(['role' => 'hr_admin']);
    $employeeUser = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()->active()->create([
        'user_id'       => $employeeUser->id,
        'department_id' => $dept->id,
    ]);

    // 3 pending + 1 approved request — only the 3 pendings should be counted.
    LeaveRequest::factory()->count(3)->pending()->create([
        'employee_id' => $employee->id,
        'type'        => LeaveType::Annual->value,
    ]);
    LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'type'        => LeaveType::Annual->value,
        'status'      => LeaveStatus::Approved->value,
    ]);

    // HR (has leave.approve) — sees the actual pending count + employees list.
    $this->actingAs($hr)
        ->get(route('leave.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Leave/Index')
            ->where('pendingCount', 3)
            ->has('employees')
        );

    // Self-service user — no approval permission, sees 0 + empty employees.
    $this->actingAs($employeeUser)
        ->get(route('leave.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Leave/Index')
            ->where('pendingCount', 0)
            ->where('employees', [])
        );
});

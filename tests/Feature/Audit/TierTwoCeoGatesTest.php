<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WhistleblowerReport;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('CEO appears in TicketController supportStaff picker', function () {
    $ceo = User::factory()->create(['role' => 'ceo', 'name' => 'CEO Person']);
    $viewer = User::factory()->create(['role' => 'hr_admin']);

    $this->actingAs($viewer)
        ->get('/tickets')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Tickets/Index')
            ->where('staff', fn ($staff) => collect($staff)->contains(fn ($s) => $s['name'] === 'CEO Person'))
        );
});

it('CEO appears in ComplaintController investigators picker', function () {
    User::factory()->create(['role' => 'ceo', 'name' => 'Exec One']);
    $viewer = User::factory()->create(['role' => 'hr_admin']);

    $this->actingAs($viewer)
        ->get('/complaints')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Complaints/Index')
            ->where('investigators', fn ($invs) => collect($invs)->contains(fn ($i) => $i['name'] === 'Exec One'))
        );
});

it('CEO appears in WhistleblowerAdmin investigators picker', function () {
    User::factory()->create(['role' => 'ceo', 'name' => 'Exec Two']);
    $viewer = User::factory()->create(['role' => 'auditor']);

    $report = WhistleblowerReport::create([
        'case_number'         => 'WB-2026-0001',
        'tracking_token_hash' => hash('sha256', 'test-token'),
        'category'            => 'fraud',
        'severity'            => 'medium',
        'subject_summary'     => 'Test report',
        'description'         => 'Investigation needed.',
        'status'              => 'submitted',
        'is_anonymous'        => true,
        'received_at'         => now(),
    ]);

    $this->actingAs($viewer)
        ->get("/admin/whistleblower/{$report->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Whistleblower/Admin/Show')
            ->where('investigators', fn ($invs) => collect($invs)->contains(fn ($i) => $i['name'] === 'Exec Two'))
        );
});

it('CEO can hit the Leave index without falling into the employee branch', function () {
    $ceo = User::factory()->create(['role' => 'ceo']);

    $this->actingAs($ceo)
        ->get('/leave-requests')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Leave/Index'));
});

it('CEO can hit the Leave show page', function () {
    $ceo = User::factory()->create(['role' => 'ceo']);
    $dept = Department::firstOrCreate(['code' => 'HR'], ['name' => 'Human Resources']);
    $emp = Employee::factory()->create(['user_id' => User::factory()->create()->id, 'department_id' => $dept->id]);

    $leave = \App\Models\LeaveRequest::create([
        'employee_id' => $emp->id,
        'type'        => 'annual',
        'start_date'  => '2026-06-01',
        'end_date'    => '2026-06-05',
        'reason'      => 'Vacation',
        'status'      => 'pending',
    ]);

    $this->actingAs($ceo)
        ->get("/leave-requests/{$leave->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Leave/Show'));
});

it('CEO dashboard returns the Inertia component (subhead is template-only)', function () {
    $ceo = User::factory()->create(['role' => 'ceo']);

    // The subhead copy lives in Dashboard.vue template — assert the dashboard
    // is hittable and shows the right page. Template assertion happens at view time.
    $this->actingAs($ceo)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Dashboard'));
});

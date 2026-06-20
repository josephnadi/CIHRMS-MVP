<?php

declare(strict_types=1);

use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('shows the compliance dashboard with per-requirement counts', function () {
    $course = Course::create(['title' => 'DPA', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
    $req = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $emp = Employee::factory()->create();
    Enrolment::create(['course_id' => $course->id, 'employee_id' => $emp->id, 'status' => 'active',
        'requirement_id' => $req->id, 'due_at' => now()->subDay(), 'enrolled_at' => now()->subDays(40)]);

    $mgr = User::factory()->create(['role' => 'hr_admin', 'permissions' => ['learning.compliance.manage']]);
    $this->actingAs($mgr)->get('/learning/compliance')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Learning/Compliance'));

    $this->actingAs(User::factory()->create(['role' => 'employee']))->get('/learning/compliance')->assertForbidden();
});

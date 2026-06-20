<?php

declare(strict_types=1);

use App\Events\EmployeeCreated;
use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

function compCourse(): Course
{
    return Course::create(['title' => 'DPA', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('auto-assigns matching requirements when an employee is created', function () {
    ComplianceRequirement::create(['course_id' => compCourse()->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $emp = Employee::factory()->active()->create();

    event(new EmployeeCreated($emp, User::factory()->create()));

    expect(Enrolment::where('employee_id', $emp->id)->mandatory()->count())->toBe(1);
});

it('compliance:sync assigns existing employees', function () {
    $emp = Employee::factory()->active()->create();
    ComplianceRequirement::create(['course_id' => compCourse()->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);

    $this->artisan('compliance:sync')->assertExitCode(0);

    expect(Enrolment::where('employee_id', $emp->id)->mandatory()->count())->toBe(1);
});

it('lets a manager create a requirement and forbids an employee', function () {
    $course = compCourse();
    $mgr = User::factory()->create(['role' => 'hr_admin', 'permissions' => ['learning.compliance.manage']]);

    $this->actingAs($mgr)->post('/learning/compliance', [
        'course_id' => $course->id, 'name' => 'Annual DPA', 'target_type' => 'all_staff', 'due_in_days' => 30,
    ])->assertRedirect();
    expect(ComplianceRequirement::where('name', 'Annual DPA')->exists())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->post('/learning/compliance', ['course_id' => $course->id, 'name' => 'x', 'target_type' => 'all_staff', 'due_in_days' => 30])
        ->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Enums\ComplianceTarget;
use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

function makeComplianceReqCourse(): Course
{
    return Course::create(['title' => 'DPA Refresher', 'category' => 'compliance',
        'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('matches all-staff, role, and department targets', function () {
    $course = makeComplianceReqCourse();
    $deptA = Department::factory()->create();
    $deptB = Department::factory()->create();

    $empA  = Employee::factory()->active()->create(['department_id' => $deptA->id, 'user_id' => User::factory()->create(['role' => 'employee'])->id]);
    $empB  = Employee::factory()->active()->create(['department_id' => $deptB->id, 'user_id' => User::factory()->create(['role' => 'employee'])->id]);
    $hr    = Employee::factory()->active()->create(['department_id' => $deptA->id, 'user_id' => User::factory()->create(['role' => 'hr_admin'])->id]);

    $all   = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $byDept = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'DeptA', 'target_type' => 'department', 'target_value' => (string) $deptA->id, 'due_in_days' => 30, 'is_active' => true]);
    $byRole = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'HR', 'target_type' => 'role', 'target_value' => 'hr_admin', 'due_in_days' => 30, 'is_active' => true]);

    expect($all->matches($empB))->toBeTrue()
        ->and($byDept->matches($empA))->toBeTrue()
        ->and($byDept->matches($empB))->toBeFalse()
        ->and($byRole->matches($hr))->toBeTrue()
        ->and($byRole->matches($empA))->toBeFalse();

    expect($byDept->matchingEmployees()->pluck('id'))->toContain($empA->id)->not->toContain($empB->id);
});

it('grants learning.compliance.manage to a learning manager, not a plain employee', function () {
    (new Database\Seeders\RolePermissionSeeder())->run();
    // hr_admin holds learning.manage (legacy ROLE_PERMISSIONS map in App\Models\User).
    $mgr = User::factory()->create(['role' => 'hr_admin']);
    expect($mgr->hasPermission('learning.compliance.manage'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('learning.compliance.manage'))->toBeFalse();
});

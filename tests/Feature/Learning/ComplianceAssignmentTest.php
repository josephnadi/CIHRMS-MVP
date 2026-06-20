<?php

declare(strict_types=1);

use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use App\Services\Learning\ComplianceAssignmentService;

beforeEach(fn () => $this->svc = app(ComplianceAssignmentService::class));

function complianceCourse(): Course
{
    return Course::create(['title' => 'DPA', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('assigns matching employees with a due date and is idempotent', function () {
    $course = complianceCourse();
    $dept = Department::factory()->create();
    $a = Employee::factory()->active()->create(['department_id' => $dept->id]);
    $b = Employee::factory()->create(); // different dept
    $req = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'R', 'target_type' => 'department', 'target_value' => (string) $dept->id, 'due_in_days' => 30, 'is_active' => true]);

    $count = $this->svc->syncRequirement($req);
    expect($count)->toBe(1);

    $enr = Enrolment::where('course_id', $course->id)->where('employee_id', $a->id)->first();
    expect($enr->requirement_id)->toBe($req->id)
        ->and($enr->due_at)->not->toBeNull()
        ->and(Enrolment::where('employee_id', $b->id)->exists())->toBeFalse(); // not targeted

    $due = $enr->due_at;
    $this->svc->syncRequirement($req); // idempotent
    expect(Enrolment::where('course_id', $course->id)->where('employee_id', $a->id)->count())->toBe(1)
        ->and($enr->fresh()->due_at->equalTo($due))->toBeTrue(); // due not moved
});

it('assigns all matching active requirements to a single employee (new-hire hook)', function () {
    $emp = Employee::factory()->active()->create();
    $c1 = complianceCourse(); $c2 = complianceCourse();
    ComplianceRequirement::create(['course_id' => $c1->id, 'name' => 'All1', 'target_type' => 'all_staff', 'due_in_days' => 14, 'is_active' => true]);
    ComplianceRequirement::create(['course_id' => $c2->id, 'name' => 'Inactive', 'target_type' => 'all_staff', 'due_in_days' => 14, 'is_active' => false]);

    expect($this->svc->assignForEmployee($emp))->toBe(1) // only the active one
        ->and(Enrolment::where('employee_id', $emp->id)->mandatory()->count())->toBe(1);
});

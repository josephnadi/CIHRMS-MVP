<?php

declare(strict_types=1);

use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;

it('flags an overdue mandatory enrolment and excludes completed / non-mandatory ones', function () {
    $course = Course::create(['title' => 'X', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
    $req = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'R', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $emp = Employee::factory()->create();

    $overdue = Enrolment::create(['course_id' => $course->id, 'employee_id' => $emp->id, 'status' => 'active',
        'requirement_id' => $req->id, 'due_at' => now()->subDay(), 'enrolled_at' => now()->subDays(40)]);
    $done = Enrolment::create(['course_id' => $course->id, 'employee_id' => Employee::factory()->create()->id, 'status' => 'completed',
        'requirement_id' => $req->id, 'due_at' => now()->subDay(), 'enrolled_at' => now()->subDays(40), 'completed_at' => now()]);
    $self = Enrolment::create(['course_id' => $course->id, 'employee_id' => Employee::factory()->create()->id, 'status' => 'active',
        'requirement_id' => null, 'enrolled_at' => now()]);

    $overdueIds = Enrolment::overdue()->pluck('id');
    expect($overdueIds)->toContain($overdue->id)->not->toContain($done->id)->not->toContain($self->id)
        ->and(Enrolment::mandatory()->pluck('id'))->toContain($overdue->id)->not->toContain($self->id);
});

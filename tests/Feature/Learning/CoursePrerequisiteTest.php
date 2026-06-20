<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;

function prereqCourse(string $title): Course
{
    return Course::create(['title' => $title, 'category' => 'technical', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('relates prerequisites and reports the unmet ones for an employee', function () {
    $basics   = prereqCourse('Basics');
    $advanced = prereqCourse('Advanced');
    $advanced->prerequisites()->attach($basics->id);

    $emp = Employee::factory()->active()->create();

    // Nothing completed yet → Basics is unmet.
    expect($advanced->prerequisites()->pluck('courses.id'))->toContain($basics->id)
        ->and($advanced->unmetPrerequisitesFor($emp)->pluck('id'))->toContain($basics->id);

    // Complete Basics → no unmet prerequisites.
    Enrolment::create(['course_id' => $basics->id, 'employee_id' => $emp->id, 'status' => 'completed', 'enrolled_at' => now(), 'completed_at' => now()]);
    expect($advanced->fresh()->unmetPrerequisitesFor($emp))->toBeEmpty();
});

<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use App\Services\LearningService;

function pCourse(string $title): Course
{
    return Course::create(['title' => $title, 'category' => 'technical', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('blocks self-enrol when a prerequisite is incomplete, allows it once completed', function () {
    $basics = pCourse('Basics');
    $advanced = pCourse('Advanced');
    $advanced->prerequisites()->attach($basics->id);

    $user = User::factory()->create(['role' => 'employee']);
    $emp  = Employee::factory()->active()->create(['user_id' => $user->id]);

    // Blocked — no enrolment created.
    $this->actingAs($user)->post(route('learning.courses.enrol', $advanced->id))->assertRedirect();
    expect(Enrolment::where('course_id', $advanced->id)->where('employee_id', $emp->id)->exists())->toBeFalse();

    // Complete the prerequisite → now allowed.
    Enrolment::create(['course_id' => $basics->id, 'employee_id' => $emp->id, 'status' => 'completed', 'enrolled_at' => now(), 'completed_at' => now()]);
    $this->actingAs($user)->post(route('learning.courses.enrol', $advanced->id))->assertRedirect();
    expect(Enrolment::where('course_id', $advanced->id)->where('employee_id', $emp->id)->exists())->toBeTrue();
});

it('does NOT block admin/automated enrol via LearningService::enrol', function () {
    $basics = pCourse('Basics');
    $advanced = pCourse('Advanced');
    $advanced->prerequisites()->attach($basics->id);
    $emp = Employee::factory()->active()->create();

    // Service-level enrol (used by compliance auto-assign + onboarding) bypasses prerequisites.
    app(LearningService::class)->enrol($advanced, $emp);
    expect(Enrolment::where('course_id', $advanced->id)->where('employee_id', $emp->id)->exists())->toBeTrue();
});

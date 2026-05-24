<?php

declare(strict_types=1);

use App\Enums\GoalStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\Goal;
use App\Models\IncidentReport;
use App\Models\User;
use Database\Seeders\IncidentPermissionsSeeder;
use Database\Seeders\RolePermissionSeeder;

/*
|--------------------------------------------------------------------------
| Tier 3 supplement — authorization holes 26-29
|--------------------------------------------------------------------------
|
| Closes four cross-tier gaps caught in the final audit-v2 review:
|   26. learning enrolments.progress — ownership gate on recordProgress
|   27. performance goals.update / goals.checkins.store — ownership gate
|   28. /ai/employee-summary — permission:ai.use + throttle:30,1
|   29. governance incidents close/reopen — defence-in-depth perm middleware
|
| Each test asserts the gate (403) and, where relevant, the legitimate
| path still works (e.g. owner CAN update their own enrolment progress).
*/

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new IncidentPermissionsSeeder())->run();
});

// ─── 26. Learning — enrolments.progress ownership gate ──────────────────────

it('forbids a non-owner without learning.manage from updating someone elses enrolment progress', function () {
    [$ownerUser, $ownerEmp] = makeEmployeeUser();
    $course   = makeCourse();
    $enrolment = Enrolment::create([
        'course_id'    => $course->id,
        'employee_id'  => $ownerEmp->id,
        'status'       => 'active',
        'progress_pct' => 10,
        'enrolled_at'  => now(),
    ]);

    // Stranger has learning.view (via 'employee' role) but no learning.manage,
    // and is NOT the enrolment owner.
    [$strangerUser] = makeEmployeeUser();

    $this->actingAs($strangerUser)
        ->patch(route('learning.enrolments.progress', $enrolment), ['progress_pct' => 99])
        ->assertForbidden();

    expect($enrolment->fresh()->progress_pct)->toEqual(10);
});

it('lets the owner update their own enrolment progress without learning.manage', function () {
    [$ownerUser, $ownerEmp] = makeEmployeeUser();
    $course   = makeCourse();
    $enrolment = Enrolment::create([
        'course_id'    => $course->id,
        'employee_id'  => $ownerEmp->id,
        'status'       => 'active',
        'progress_pct' => 10,
        'enrolled_at'  => now(),
    ]);

    $this->actingAs($ownerUser)
        ->patch(route('learning.enrolments.progress', $enrolment), ['progress_pct' => 75])
        ->assertRedirect();

    expect((float) $enrolment->fresh()->progress_pct)->toBe(75.0);
});

// ─── 27. Performance — goals.update / goals.checkins.store ownership gates ──

it('forbids a non-owner without performance.manage from updating someone elses goal', function () {
    [$ownerUser, $ownerEmp] = makeEmployeeUser();
    $goal = Goal::create([
        'employee_id' => $ownerEmp->id,
        'title'       => 'Owner goal',
        'cadence'     => 'quarterly',
        'status'      => GoalStatus::Active->value,
        'created_by'  => $ownerUser->id,
    ]);

    [$strangerUser] = makeEmployeeUser();

    $this->actingAs($strangerUser)
        ->patch(route('performance.goals.update', $goal), ['title' => 'Hacked'])
        ->assertForbidden();

    expect($goal->fresh()->title)->toBe('Owner goal');
});

it('forbids a non-owner without performance.manage from posting a check-in on someone elses goal', function () {
    [$ownerUser, $ownerEmp] = makeEmployeeUser();
    $goal = Goal::create([
        'employee_id' => $ownerEmp->id,
        'title'       => 'Owner goal',
        'cadence'     => 'monthly',
        'status'      => GoalStatus::Active->value,
        'created_by'  => $ownerUser->id,
    ]);

    [$strangerUser] = makeEmployeeUser();

    $this->actingAs($strangerUser)
        ->post(route('performance.goals.checkins.store', $goal), [
            'progress_pct' => 50,
            'narrative'    => 'Hijacked check-in',
            'mood'         => 'green',
        ])
        ->assertForbidden();

    expect($goal->checkins()->count())->toBe(0);
});

// ─── 28. AI — /ai/employee-summary requires ai.use ──────────────────────────

it('forbids a user without ai.use from hitting /ai/employee-summary', function () {
    [$user, $emp] = makeEmployeeUser(); // 'employee' role does NOT have ai.use

    $this->actingAs($user)
        ->postJson(route('ai.employee-summary'), [
            'employee_id' => $emp->id,
            'prompt'      => 'Tell me about this person.',
        ])
        ->assertForbidden();
});

// ─── 29. Incidents — defence-in-depth on reviewer-only mutations ────────────

it('forbids a user without incidents.review from closing an incident at the route layer', function () {
    [$submitterUser, $submitterEmp] = makeEmployeeUser();
    $report = IncidentReport::create([
        'employee_id' => $submitterEmp->id,
        'category'    => 'grievance',
        'title'       => 'Concern about overtime policy',
        'body'        => 'Discussion needed about how overtime has been applied across the team.',
        'status'      => 'open',
    ]);

    // Even the submitter (who has policy-level rights for some actions) lacks
    // incidents.review, so the close route is closed at the middleware layer.
    $this->actingAs($submitterUser)
        ->post(route('incidents.close', $report), ['resolution_note' => 'I want to close.'])
        ->assertForbidden();

    expect($report->fresh()->status->value)->toBe('open');
});

// ─── helpers ────────────────────────────────────────────────────────────────

/**
 * @return array{0: User, 1: Employee}
 */
function makeEmployeeUser(): array
{
    $user = User::factory()->create(['role' => 'employee']);
    $emp  = Employee::factory()->active()->create(['user_id' => $user->id]);
    return [$user, $emp];
}

function makeCourse(): Course
{
    return Course::create([
        'title'            => 'Audit Test Course '.uniqid(),
        'description'      => 'Course used by tier-3 supplement permission tests.',
        'category'         => 'compliance',
        'format'           => 'self_paced',
        'duration_minutes' => 60,
        'is_published'     => true,
        'published_at'     => now(),
    ]);
}

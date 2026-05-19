<?php

use App\Models\Employee;
use App\Models\IncidentReport;
use App\Models\User;
use App\Policies\IncidentReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeReportWithAssignee(): array {
    $submitterUser = User::factory()->create();
    $submitter     = Employee::factory()->create(['user_id' => $submitterUser->id]);
    $report = IncidentReport::create([
        'employee_id' => $submitter->id,
        'category'    => 'grievance',
        'title'       => 'Test',
        'body'        => 'Lorem ipsum dolor sit amet.',
        'status'      => 'open',
    ]);
    $reviewer = User::factory()->create(['permissions' => ['incidents.review']]);
    $report->assignees()->attach($reviewer->id, [
        'assigned_at'    => now(),
        'assigned_by_id' => $submitterUser->id,
    ]);
    return compact('report', 'submitterUser', 'reviewer');
}

test('view: submitter can view their own report', function () {
    ['report' => $r, 'submitterUser' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->view($u, $r))->toBeTrue();
});

test('view: current assignee can view', function () {
    ['report' => $r, 'reviewer' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->view($u, $r))->toBeTrue();
});

test('view: removed assignee cannot view', function () {
    ['report' => $r, 'reviewer' => $u] = makeReportWithAssignee();
    $r->assignees()->updateExistingPivot($u->id, ['removed_at' => now()]);
    expect((new IncidentReportPolicy)->view($u->fresh(), $r->fresh()))->toBeFalse();
});

test('view: unrelated employee cannot view', function () {
    ['report' => $r] = makeReportWithAssignee();
    $stranger = User::factory()->create();
    Employee::factory()->create(['user_id' => $stranger->id]);
    expect((new IncidentReportPolicy)->view($stranger, $r))->toBeFalse();
});

test('view: super_admin without assignment cannot view (privacy invariant)', function () {
    ['report' => $r] = makeReportWithAssignee();
    $sa = User::factory()->create(['role' => 'super_admin']);
    expect((new IncidentReportPolicy)->view($sa, $r))->toBeFalse();
});

test('create: only users with an employee row can submit', function () {
    $userWithEmployee = User::factory()->create();
    Employee::factory()->create(['user_id' => $userWithEmployee->id]);
    expect((new IncidentReportPolicy)->create($userWithEmployee))->toBeTrue();

    $userWithoutEmployee = User::factory()->create(['role' => 'super_admin']);
    expect((new IncidentReportPolicy)->create($userWithoutEmployee))->toBeFalse();
});

test('update: submitter can edit while open and no assignees yet', function () {
    $u = User::factory()->create();
    $e = Employee::factory()->create(['user_id' => $u->id]);
    $r = IncidentReport::create([
        'employee_id' => $e->id, 'category' => 'other',
        'title' => 'T', 'body' => 'B body B body', 'status' => 'open',
    ]);
    expect((new IncidentReportPolicy)->update($u, $r))->toBeTrue();
});

test('update: submitter cannot edit after first assignment', function () {
    ['report' => $r, 'submitterUser' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->update($u, $r))->toBeFalse();
});

test('close: only current assignees can close', function () {
    ['report' => $r, 'submitterUser' => $sub, 'reviewer' => $rev] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->close($rev, $r))->toBeTrue();
    expect((new IncidentReportPolicy)->close($sub, $r))->toBeFalse();
});

test('postMessage: requires view + status not closed', function () {
    ['report' => $r, 'submitterUser' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->postMessage($u, $r))->toBeTrue();
    $r->update(['status' => 'closed']);
    expect((new IncidentReportPolicy)->postMessage($u, $r->fresh()))->toBeFalse();
});

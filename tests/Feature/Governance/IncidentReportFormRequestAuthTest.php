<?php

use App\Models\Employee;
use App\Models\IncidentReport;
use App\Models\User;

function makeReport(int $reporterEmpId): IncidentReport
{
    return IncidentReport::create([
        'employee_id' => $reporterEmpId,
        'category'    => 'grievance',
        'title'       => 'X',
        'body'        => 'Y',
        'status'      => 'open',
    ]);
}

it('rejects close from a user who is not on the assignee list (FormRequest gate)', function () {
    $reporter = User::factory()->create();
    $emp      = Employee::factory()->create(['user_id' => $reporter->id]);
    $report   = makeReport($emp->id);

    $randomUser = User::factory()->create();
    $this->actingAs($randomUser)
        ->post(route('incidents.close', $report), ['resolution_note' => 'x'])
        ->assertForbidden();
});

it('rejects assign from a user who is neither the reporter nor an assignee', function () {
    $reporter = User::factory()->create();
    $emp      = Employee::factory()->create(['user_id' => $reporter->id]);
    $report   = makeReport($emp->id);

    $randomUser = User::factory()->create();
    $reviewer = User::factory()->create(['permissions' => ['incidents.review']]);

    $this->actingAs($randomUser)
        ->post(route('incidents.assign', $report), ['user_id' => $reviewer->id])
        ->assertForbidden();
});

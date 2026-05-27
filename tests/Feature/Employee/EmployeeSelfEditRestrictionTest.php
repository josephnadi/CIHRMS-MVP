<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

it('strips HR-only fields when a self-editing employee tries to mutate them', function () {
    $deptA = Department::factory()->create(['code' => 'AAA', 'name' => 'A']);
    $deptB = Department::factory()->create(['code' => 'BBB', 'name' => 'B']);

    // Pin the role to `employee` — the User factory randomly picks from
    // ['employee','manager','hr_admin','finance_officer'], and a roll of
    // hr_admin grants `employees.manage` via the legacy ROLE_PERMISSIONS
    // mapping, which would bypass the strip and flake this test 1-in-4.
    $user = User::factory()->create(['role' => 'employee', 'permissions' => []]);
    $emp  = Employee::factory()->create([
        'user_id'       => $user->id,
        'department_id' => $deptA->id,
        'position'      => 'Analyst',
        'status'        => 'active',
    ]);

    $resp = $this->actingAs($user)->patch(route('employees.update', $emp), [
        'department_id' => $deptB->id,             // HR-only — should be ignored
        'manager_id'    => null,                   // HR-only — should be ignored
        'status'        => 'inactive',             // HR-only — should be ignored
        'position'      => 'Director',             // HR-only — should be ignored
        'phone'         => '+233200000000',        // self-editable — should land
    ]);

    $emp->refresh();
    expect($emp->department_id)->toBe($deptA->id);
    expect($emp->status->value ?? $emp->status)->toBe('active');
    expect($emp->position)->toBe('Analyst');
    expect($emp->phone)->toBe('+233200000000');
});

it('allows HR-permitted users to mutate HR-only fields', function () {
    $deptA = Department::factory()->create(['code' => 'AAA', 'name' => 'A']);
    $deptB = Department::factory()->create(['code' => 'BBB', 'name' => 'B']);

    $hr   = User::factory()->create(['permissions' => ['employees.manage', 'employees.view_salary']]);
    $emp  = Employee::factory()->create([
        'department_id' => $deptA->id,
        'position'      => 'Analyst',
    ]);

    $this->actingAs($hr)->patch(route('employees.update', $emp), [
        'department_id' => $deptB->id,
        'position'      => 'Director',
    ])->assertRedirect();

    expect($emp->fresh()->department_id)->toBe($deptB->id);
    expect($emp->fresh()->position)->toBe('Director');
});

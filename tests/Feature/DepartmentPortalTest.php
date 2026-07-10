<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

it('renders the department portal with a real headcount and roster', function () {
    $dept = Department::factory()->create(['code' => 'HR', 'name' => 'Human Resources']);
    $viewer = User::factory()->create();

    Employee::factory()->count(3)->create(['department_id' => $dept->id, 'status' => 'active']);
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated']); // excluded

    $this->actingAs($viewer)
        ->get(route('departments.portal', 'hr'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Departments/Show')
            ->where('headcount', 3)
            ->has('members', 3)
            ->where('department.code', 'HR'));
});

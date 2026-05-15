<?php

use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

beforeEach(function () {
    $this->dept = Department::factory()->create();
    $this->hr = User::factory()->create(['role' => 'hr_admin']);
});

test('HR can list employees', function () {
    Employee::factory()->count(4)->create(['department_id' => $this->dept->id]);

    $this->actingAs($this->hr)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Employees/Index')
            ->has('employees.data', 4)
            ->has('departments')
        );
});

test('HR can view a specific employee', function () {
    $employee = Employee::factory()->create(['department_id' => $this->dept->id]);

    $this->actingAs($this->hr)
        ->get(route('employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Employees/Show')
            ->where('employee.data.id', $employee->id)
        );
});

test('HR can create an employee with an inline user account', function () {
    $this->actingAs($this->hr)
        ->post(route('employees.store'), [
            'create_user'   => true,
            'user_name'     => 'Yaa Mensah',
            'user_email'    => 'yaa.mensah@example.com',
            'user_role'     => 'employee',
            'user_password' => 'StrongP@ss1',
            'staff_id'      => 'GH-OPS-200',
            'department_id' => $this->dept->id,
            'employee_no'   => 'CIHRM-TEST-1',
            'position'      => 'Operations Analyst',
            'hire_date'     => now()->subYear()->toDateString(),
            'phone'         => '+233244111222',
            'status'        => EmployeeStatus::Active->value,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('users',     ['email' => 'yaa.mensah@example.com', 'staff_id' => 'GH-OPS-200']);
    $this->assertDatabaseHas('employees', ['employee_no' => 'CIHRM-TEST-1', 'department_id' => $this->dept->id]);
});

test('HR can update an employee', function () {
    $employee = Employee::factory()->create([
        'department_id' => $this->dept->id,
        'position'      => 'Junior Analyst',
    ]);

    $this->actingAs($this->hr)
        ->patch(route('employees.update', $employee), [
            'position' => 'Senior Analyst',
            'status'   => EmployeeStatus::Active->value,
        ])
        ->assertRedirect();

    expect($employee->fresh()->position)->toBe('Senior Analyst');
});

test('HR can soft-delete an employee', function () {
    $employee = Employee::factory()->create(['department_id' => $this->dept->id]);

    $this->actingAs($this->hr)
        ->delete(route('employees.destroy', $employee))
        ->assertRedirect();

    $this->assertSoftDeleted('employees', ['id' => $employee->id]);
});

test('employee without permissions cannot list employees', function () {
    $employeeUser = User::factory()->create(['role' => 'employee']);
    Employee::factory()->create(['user_id' => $employeeUser->id, 'department_id' => $this->dept->id]);

    $this->actingAs($employeeUser)
        ->get(route('employees.index'))
        ->assertForbidden();
});

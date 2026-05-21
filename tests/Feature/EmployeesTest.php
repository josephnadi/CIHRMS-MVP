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

test('HR can create an employee without providing employee_no or staff_id (both auto-assigned)', function () {
    $this->actingAs($this->hr)
        ->post(route('employees.store'), [
            'create_user'   => true,
            'user_name'     => 'Auto Generated',
            'user_email'    => 'auto.gen@example.com',
            'user_role'     => 'employee',
            'user_password' => 'StrongP@ss1',
            // staff_id and employee_no intentionally omitted
            'department_id' => $this->dept->id,
            'position'      => 'Analyst',
            'hire_date'     => now()->subYear()->toDateString(),
            'status'        => EmployeeStatus::Active->value,
        ])
        ->assertRedirect();

    $user = User::where('email', 'auto.gen@example.com')->firstOrFail();
    $employee = Employee::where('user_id', $user->id)->firstOrFail();

    expect($user->staff_id)->toStartWith('SID-');
    expect($employee->employee_no)->toStartWith('CIHRM-');
});

test('HR can enrol a new employee in benefit plans at creation time', function () {
    $health = \App\Models\BenefitPlan::create([
        'name'                              => 'Health Cover',
        'code'                              => 'HLT-01',
        'type'                              => 'health_insurance',
        'provider'                          => 'Cosmopolitan',
        'monthly_cost'                      => 200,
        'employee_contribution_percentage'  => 25,
        'is_active'                         => true,
        'effective_from'                    => now()->subMonth()->toDateString(),
    ]);
    $dental = \App\Models\BenefitPlan::create([
        'name'                              => 'Dental',
        'code'                              => 'DNT-01',
        'type'                              => 'dental',
        'provider'                          => 'GhanaDental',
        'monthly_cost'                      => 80,
        'employee_contribution_percentage'  => 0,
        'is_active'                         => true,
        'effective_from'                    => now()->subMonth()->toDateString(),
    ]);

    $this->actingAs($this->hr)
        ->post(route('employees.store'), [
            'create_user'      => true,
            'user_name'        => 'Yaa Twum',
            'user_email'       => 'yaa.twum@example.com',
            'user_role'        => 'employee',
            'user_password'    => 'StrongP@ss1',
            'department_id'    => $this->dept->id,
            'position'         => 'Analyst',
            'hire_date'        => now()->subYear()->toDateString(),
            'status'           => EmployeeStatus::Active->value,
            'benefit_plan_ids' => [$health->id, $dental->id],
        ])
        ->assertRedirect();

    $user = User::where('email', 'yaa.twum@example.com')->firstOrFail();
    $employee = Employee::where('user_id', $user->id)->firstOrFail();

    expect($employee->benefitEnrolments()->count())->toBe(2);
    expect($employee->benefitEnrolments()->pluck('plan_id')->all())
        ->toContain($health->id, $dental->id);
});

test('auto-assigned employee_no is unique across two back-to-back creations', function () {
    $payload = fn (string $email) => [
        'create_user'   => true,
        'user_name'     => 'Person ' . $email,
        'user_email'    => $email,
        'user_role'     => 'employee',
        'user_password' => 'StrongP@ss1',
        'department_id' => $this->dept->id,
        'position'      => 'Analyst',
        'hire_date'     => now()->subYear()->toDateString(),
        'status'        => EmployeeStatus::Active->value,
    ];

    $this->actingAs($this->hr)->post(route('employees.store'), $payload('a@example.com'))->assertRedirect();
    $this->actingAs($this->hr)->post(route('employees.store'), $payload('b@example.com'))->assertRedirect();

    $nos = Employee::orderBy('id')->pluck('employee_no')->take(-2)->values();
    $sids = User::whereIn('email', ['a@example.com', 'b@example.com'])->pluck('staff_id');

    expect($nos->unique()->count())->toBe(2);
    expect($sids->unique()->count())->toBe(2);
});

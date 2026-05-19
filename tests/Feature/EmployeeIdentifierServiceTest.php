<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeIdentifierService;

beforeEach(function () {
    $this->ids = new EmployeeIdentifierService();
    $this->dept = Department::factory()->create();
});

it('starts at CIHRM-0001 on an empty employees table', function () {
    Employee::query()->forceDelete();

    expect($this->ids->nextEmployeeNo())->toBe('CIHRM-0001');
});

it('increments past the highest existing CIHRM- sequence', function () {
    Employee::factory()->create([
        'department_id' => $this->dept->id,
        'employee_no'   => 'CIHRM-0042',
    ]);

    expect($this->ids->nextEmployeeNo())->toBe('CIHRM-0043');
});

it('ignores non-CIHRM employee numbers when picking the next sequence', function () {
    Employee::factory()->create([
        'department_id' => $this->dept->id,
        'employee_no'   => 'GH-HR-9999', // legacy / hand-entered, must not influence sequence
    ]);
    Employee::factory()->create([
        'department_id' => $this->dept->id,
        'employee_no'   => 'CIHRM-0007',
    ]);

    expect($this->ids->nextEmployeeNo())->toBe('CIHRM-0008');
});

it('produces unique employee numbers across many sequential calls', function () {
    $emitted = [];
    for ($i = 0; $i < 10; $i++) {
        $no = $this->ids->nextEmployeeNo();
        $emitted[] = $no;
        Employee::factory()->create([
            'department_id' => $this->dept->id,
            'employee_no'   => $no,
        ]);
    }

    expect($emitted)->toBe(array_unique($emitted));
    expect(count($emitted))->toBe(10);
});

it('auto-widens beyond 9999 without losing uniqueness', function () {
    Employee::factory()->create([
        'department_id' => $this->dept->id,
        'employee_no'   => 'CIHRM-9999',
    ]);

    expect($this->ids->nextEmployeeNo())->toBe('CIHRM-10000');
});

it('starts at SID-000001 on an empty users table', function () {
    User::query()->forceDelete();

    expect($this->ids->nextStaffId())->toBe('SID-000001');
});

it('increments past the highest existing SID- staff_id', function () {
    User::factory()->create(['staff_id' => 'SID-000099']);

    expect($this->ids->nextStaffId())->toBe('SID-000100');
});

it('ignores non-SID staff_ids when picking the next sequence', function () {
    User::factory()->create(['staff_id' => 'GH-HR-001']);
    User::factory()->create(['staff_id' => 'SID-000005']);

    expect($this->ids->nextStaffId())->toBe('SID-000006');
});

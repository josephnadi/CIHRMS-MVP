<?php

use App\Models\Department;
use App\Models\Employee;
use App\Services\Ai\PiiRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(\Tests\TestCase::class, RefreshDatabase::class);

it('only emits allow-listed fields', function () {
    $dept = Department::factory()->create(['name' => 'IT']);
    $employee = Employee::factory()->create([
        'department_id'           => $dept->id,
        'employee_no'             => 'EMP-001',
        'position'                => 'Engineer',
        'phone'                   => '+233244000000',
        'national_id'             => 'GHA-X',
        'ssnit_number'            => 'C-X',
        'tin_number'              => 'P-X',
        'bank_account'            => '9999',
        'salary'                  => 12_000,
        'address'                 => 'somewhere',
        'emergency_contact_phone' => '+233200000001',
    ]);

    $employee->load('department');

    $out = (new PiiRedactor())->redact($employee);

    expect(array_keys($out))->toMatchArray([
        'employee_no', 'position', 'department', 'status',
        'hire_date', 'tenure_years', 'gender', 'has_manager',
    ]);

    expect($out['department'])->toBe('IT');
    expect($out['employee_no'])->toBe('EMP-001');
    expect(array_key_exists('national_id', $out))->toBeFalse();
    expect(array_key_exists('phone', $out))->toBeFalse();
    expect(array_key_exists('salary', $out))->toBeFalse();
    expect(array_key_exists('bank_account', $out))->toBeFalse();
});

it('applyBlocklist drops any blocked key even if it leaks in', function () {
    $out = (new PiiRedactor())->applyBlocklist([
        'employee_no'  => 'X',
        'phone'        => '+233',
        'national_id'  => 'GHA',
        'bank_account' => '999',
        'safe_field'   => 'kept',
    ]);

    expect($out)->toBe([
        'employee_no' => 'X',
        'safe_field'  => 'kept',
    ]);
});

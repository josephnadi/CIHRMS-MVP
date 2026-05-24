<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->hrDept = Department::firstOrCreate(['code' => 'HR'], ['name' => 'Human Resources']);
});

it('provisions an Employee row for an existing user by staff_id', function () {
    $user = User::factory()->create([
        'staff_id' => 'CEO-EXISTING',
        'email'    => 'existing-ceo@cihrm.local',
        'role'     => 'ceo',
    ]);
    expect(Employee::where('user_id', $user->id)->exists())->toBeFalse();

    $this->artisan('users:provision-employee', [
        '--staff-id'   => 'CEO-EXISTING',
        '--department' => 'HR',
        '--position'   => 'Chief Executive Officer',
    ])
        ->expectsOutputToContain('CEO-EXISTING')
        ->expectsOutputToContain('Provisioned 1')
        ->assertSuccessful();

    $emp = Employee::where('user_id', $user->id)->firstOrFail();
    expect($emp->position)->toBe('Chief Executive Officer');
    expect($emp->department_id)->toBe($this->hrDept->id);
    expect($emp->employee_no)->toMatch('/^CIHRM-\d{4}$/');
});

it('skips users that already have an Employee row', function () {
    $user = User::factory()->create(['staff_id' => 'ALREADY-001']);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $this->hrDept->id, 'employee_no' => 'CIHRM-7777']);

    $this->artisan('users:provision-employee', [
        '--staff-id'   => 'ALREADY-001',
        '--department' => 'HR',
    ])
        ->expectsOutputToContain('already has Employee #CIHRM-7777')
        ->expectsOutputToContain('Provisioned 0 employee row(s); skipped 1')
        ->assertSuccessful();
});

it('--all-missing bulk-backfills every user with no employee', function () {
    User::factory()->count(3)->create();   // 3 users with no Employee rows

    // One pre-existing user WITH an Employee — should be skipped
    $linked = User::factory()->create();
    Employee::factory()->create(['user_id' => $linked->id, 'department_id' => $this->hrDept->id]);

    $this->artisan('users:provision-employee', [
        '--all-missing' => true,
        '--department'  => 'HR',
    ])
        ->assertSuccessful();

    expect(User::query()->whereDoesntHave('employee')->count())->toBe(0);
});

it('errors when --department is not passed', function () {
    User::factory()->create(['staff_id' => 'X-001']);

    $this->artisan('users:provision-employee', ['--staff-id' => 'X-001'])
        ->expectsOutputToContain('--department is required')
        ->assertFailed();
});

it('warns when no targeting flag is passed and exits without provisioning', function () {
    $this->artisan('users:provision-employee', ['--department' => 'HR'])
        ->expectsOutputToContain('Pass one of: --staff-id, --email, or --all-missing.')
        ->expectsOutputToContain('No users to provision')
        ->assertSuccessful();
});

it('errors when department name does not match', function () {
    User::factory()->create(['staff_id' => 'Y-001']);

    $this->artisan('users:provision-employee', [
        '--staff-id'   => 'Y-001',
        '--department' => 'NotARealDept',
    ])
        ->expectsOutputToContain('No department found')
        ->assertFailed();
});

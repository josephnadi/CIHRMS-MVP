<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->dept = Department::firstOrCreate(['code' => 'HR'], ['name' => 'Human Resources']);
});

function basePayload(array $overrides = []): array
{
    return array_merge([
        'name'                  => 'Default Name',
        'email'                 => 'default@cihrm.local',
        'staff_id'              => 'DEF-001',
        'role'                  => 'employee',
        'password'              => 'StrongPass!1',
        'password_confirmation' => 'StrongPass!1',
        'department_id'         => test()->dept->id,
        'position'              => 'Staff',
        'hire_date'             => '2026-05-24',
    ], $overrides);
}

it('super_admin can list users at /admin/users', function () {
    $u = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($u)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/Users/Index')
            ->has('users')
            ->has('roles')
            ->has('departments')
        );
});

it('hr_admin can list users (has users.manage)', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);

    $this->actingAs($u)->get('/admin/users')->assertOk();
});

it('employee gets 403 on /admin/users', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)->get('/admin/users')->assertForbidden();
});

it('super_admin can create a super_admin account with employee profile', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', basePayload([
            'name'     => 'Lawrence Boateng',
            'email'    => 'lawrence@cihrm.local',
            'staff_id' => 'SA-002',
            'role'     => 'super_admin',
            'password' => 'Sup3r-Sec!ret',
            'password_confirmation' => 'Sup3r-Sec!ret',
            'position' => 'Director of Engineering',
        ]))
        ->assertRedirect();

    $created = User::where('email', 'lawrence@cihrm.local')->firstOrFail();
    expect($created->role->value)->toBe('super_admin');
    expect($created->two_factor_required)->toBeTrue();
    expect($created->password_must_change)->toBeTrue();
    expect(Hash::check('Sup3r-Sec!ret', $created->password))->toBeTrue();

    // Employee row was provisioned atomically — HR feature pages will work.
    $emp = Employee::where('user_id', $created->id)->first();
    expect($emp)->not->toBeNull();
    expect($emp->position)->toBe('Director of Engineering');
    expect($emp->department_id)->toBe($this->dept->id);
    expect($emp->employee_no)->toMatch('/^CIHRM-\d{4}$/');
});

it('super_admin can create a CEO account with 2FA forced on', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', basePayload([
            'name'                => 'Adwoa Owusu',
            'email'               => 'ceo@cihrm.local',
            'staff_id'            => 'CEO-001',
            'role'                => 'ceo',
            'password'            => 'Ex3cutive-Pwd!',
            'password_confirmation' => 'Ex3cutive-Pwd!',
            'two_factor_required' => false, // operator's attempt — should be ignored
            'position'            => 'Chief Executive Officer',
        ]))
        ->assertRedirect();

    $ceo = User::where('email', 'ceo@cihrm.local')->firstOrFail();
    expect($ceo->role->value)->toBe('ceo');
    expect($ceo->two_factor_required)->toBeTrue();
    expect($ceo->employee)->not->toBeNull();
    expect($ceo->employee->position)->toBe('Chief Executive Officer');
});

it('honours an operator-supplied employee_no instead of auto-generating', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', basePayload([
            'email'       => 'custom@cihrm.local',
            'staff_id'    => 'CUST-001',
            'employee_no' => 'CIHRM-9999',
        ]))
        ->assertRedirect();

    $user = User::where('email', 'custom@cihrm.local')->firstOrFail();
    expect($user->employee->employee_no)->toBe('CIHRM-9999');
});

it('rejects when employee profile fields are missing', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name'                  => 'NoEmp',
            'email'                 => 'noemp@cihrm.local',
            'staff_id'              => 'NOE-001',
            'role'                  => 'employee',
            'password'              => 'StrongPass!1',
            'password_confirmation' => 'StrongPass!1',
            // missing department_id, position, hire_date
        ])
        ->assertSessionHasErrors(['department_id', 'position', 'hire_date']);

    expect(User::where('email', 'noemp@cihrm.local')->exists())->toBeFalse();
});

it('rejects a duplicate staff_id', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    User::factory()->create(['staff_id' => 'CLASH-1']);

    $this->actingAs($admin)
        ->post('/admin/users', basePayload([
            'email'    => 'x@cihrm.local',
            'staff_id' => 'CLASH-1',
        ]))
        ->assertSessionHasErrors('staff_id');
});

it('rejects mismatched password confirmation', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', basePayload([
            'email'    => 'y@cihrm.local',
            'staff_id' => 'Y-001',
            'password' => 'StrongPass!1',
            'password_confirmation' => 'WrongConfirm!1',
        ]))
        ->assertSessionHasErrors('password');
});

it('employee cannot create a user', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->post('/admin/users', basePayload([
            'email'    => 'h@cihrm.local',
            'staff_id' => 'H-001',
            'role'     => 'super_admin',
        ]))
        ->assertForbidden();

    expect(User::where('email', 'h@cihrm.local')->exists())->toBeFalse();
});

it('CEO role has full access (wildcard, mirrors super_admin)', function () {
    $ceo = User::factory()->create(['role' => 'ceo']);

    // Strategic + governance + read-only finance — kept from the original set
    expect($ceo->hasPermission('payroll.approve'))->toBeTrue();
    expect($ceo->hasPermission('loans.approve'))->toBeTrue();
    expect($ceo->hasPermission('governance.manage'))->toBeTrue();
    expect($ceo->hasPermission('audit.view'))->toBeTrue();
    expect($ceo->hasPermission('ap_invoices.view'))->toBeTrue();
    expect($ceo->hasPermission('reconciliation.view'))->toBeTrue();
    expect($ceo->hasPermission('employees.view_salary'))->toBeTrue();

    // Previously curated-out — now granted because CEO mirrors super_admin
    expect($ceo->hasPermission('employees.manage'))->toBeTrue();
    expect($ceo->hasPermission('payroll.run'))->toBeTrue();
    expect($ceo->hasPermission('loans.disburse'))->toBeTrue();
    expect($ceo->hasPermission('ap_invoices.pay'))->toBeTrue();
    expect($ceo->hasPermission('ar_invoices.write_off'))->toBeTrue();
    expect($ceo->hasPermission('journal.post_manual'))->toBeTrue();

    // Wildcard implies any future permission too — including ones we haven't named.
    expect($ceo->hasPermission('a-future-permission-we-have-not-defined'))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('super_admin can list users at /admin/users', function () {
    $u = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($u)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/Users/Index')
            ->has('users')
            ->has('roles')
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

it('super_admin can create a super_admin account', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name'                  => 'Lawrence Boateng',
            'email'                 => 'lawrence@cihrm.local',
            'staff_id'              => 'SA-002',
            'role'                  => 'super_admin',
            'password'              => 'Sup3r-Sec!ret',
            'password_confirmation' => 'Sup3r-Sec!ret',
        ])
        ->assertRedirect();

    $created = User::where('email', 'lawrence@cihrm.local')->firstOrFail();
    expect($created->role->value)->toBe('super_admin');
    expect($created->two_factor_required)->toBeTrue();
    expect($created->password_must_change)->toBeTrue();
    expect(Hash::check('Sup3r-Sec!ret', $created->password))->toBeTrue();
});

it('super_admin can create a CEO account with 2FA forced on', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name'                  => 'Adwoa Owusu',
            'email'                 => 'ceo@cihrm.local',
            'staff_id'              => 'CEO-001',
            'role'                  => 'ceo',
            'password'              => 'Ex3cutive-Pwd!',
            'password_confirmation' => 'Ex3cutive-Pwd!',
            'two_factor_required'   => false, // operator's attempt — should be ignored
        ])
        ->assertRedirect();

    $ceo = User::where('email', 'ceo@cihrm.local')->firstOrFail();
    expect($ceo->role->value)->toBe('ceo');
    expect($ceo->two_factor_required)->toBeTrue();
});

it('rejects a duplicate staff_id', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    User::factory()->create(['staff_id' => 'CLASH-1']);

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name'                  => 'X',
            'email'                 => 'x@cihrm.local',
            'staff_id'              => 'CLASH-1',
            'role'                  => 'employee',
            'password'              => 'StrongPass!1',
            'password_confirmation' => 'StrongPass!1',
        ])
        ->assertSessionHasErrors('staff_id');
});

it('rejects mismatched password confirmation', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name'                  => 'Y',
            'email'                 => 'y@cihrm.local',
            'staff_id'              => 'Y-001',
            'role'                  => 'employee',
            'password'              => 'StrongPass!1',
            'password_confirmation' => 'WrongConfirm!1',
        ])
        ->assertSessionHasErrors('password');
});

it('employee cannot create a user', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->post('/admin/users', [
            'name'                  => 'Hacker',
            'email'                 => 'h@cihrm.local',
            'staff_id'              => 'H-001',
            'role'                  => 'super_admin',
            'password'              => 'Whatever1!',
            'password_confirmation' => 'Whatever1!',
        ])
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

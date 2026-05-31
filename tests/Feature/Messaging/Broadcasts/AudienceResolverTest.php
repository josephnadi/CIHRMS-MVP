<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\EmployeeStatus;
use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use App\Services\Messaging\Broadcasts\AudienceResolver;

beforeEach(function () {
    $this->resolver = app(AudienceResolver::class);
});

it('resolves AllActiveMembers to active members only', function () {
    Member::factory()->state(['status' => MemberStatus::Active])->count(3)->create();
    Member::factory()->state(['status' => MemberStatus::Resigned])->create();

    $count = $this->resolver->resolve(BroadcastAudienceType::AllActiveMembers, [])->count();

    expect($count)->toBe(3);
});

it('resolves MembersByClass to the given class', function () {
    Member::factory()->state([
        'status' => MemberStatus::Active, 'class' => MemberClass::Professional,
    ])->count(2)->create();
    Member::factory()->state([
        'status' => MemberStatus::Active, 'class' => MemberClass::Student,
    ])->create();

    $count = $this->resolver->resolve(
        BroadcastAudienceType::MembersByClass,
        ['class' => MemberClass::Professional->value],
    )->count();

    expect($count)->toBe(2);
});

it('resolves AllActiveEmployees to active employees only', function () {
    Employee::factory()->state(['status' => EmployeeStatus::Active])->count(4)->create();
    Employee::factory()->state(['status' => EmployeeStatus::Terminated])->create();

    $count = $this->resolver->resolve(BroadcastAudienceType::AllActiveEmployees, [])->count();

    expect($count)->toBe(4);
});

it('resolves EmployeesByDepartment scoped to dept_id', function () {
    $deptA = Department::factory()->create();
    $deptB = Department::factory()->create();
    Employee::factory()->state(['status' => EmployeeStatus::Active, 'department_id' => $deptA->id])->count(3)->create();
    Employee::factory()->state(['status' => EmployeeStatus::Active, 'department_id' => $deptB->id])->create();

    $count = $this->resolver->resolve(
        BroadcastAudienceType::EmployeesByDepartment,
        ['department_id' => $deptA->id],
    )->count();

    expect($count)->toBe(3);
});

it('resolves UsersByPermission via JSON permissions column', function () {
    $u1 = User::factory()->create(['role' => 'employee']);
    $u1->permissions = ['payroll.view']; $u1->save();
    $u2 = User::factory()->create(['role' => 'employee']);
    $u2->permissions = ['payroll.view']; $u2->save();
    User::factory()->create(['role' => 'employee']); // no perm

    $count = $this->resolver->resolve(
        BroadcastAudienceType::UsersByPermission,
        ['permission' => 'payroll.view'],
    )->count();

    expect($count)->toBe(2);
});

it('returns a Builder so DispatchBroadcastJob can chunkById', function () {
    $result = $this->resolver->resolve(BroadcastAudienceType::AllActiveMembers, []);
    expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
});

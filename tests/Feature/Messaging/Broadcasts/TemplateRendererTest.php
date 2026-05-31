<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use App\Services\Messaging\Broadcasts\TemplateRenderer;

beforeEach(function () {
    $this->renderer = app(TemplateRenderer::class);
});

it('renders member.name and member.member_no for member audience', function () {
    $member = Member::factory()->state([
        'name' => 'Akua Mensah', 'member_no' => 'CIHRM-M-2026-00007',
    ])->create();

    $out = $this->renderer->render(
        'Hello {{member.name}}, your number is {{member.member_no}}.',
        $member,
        BroadcastAudienceType::AllActiveMembers,
    );

    expect($out)->toBe('Hello Akua Mensah, your number is CIHRM-M-2026-00007.');
});

it('renders employee.name + employee.department for employee audience', function () {
    $dept = Department::factory()->state(['name' => 'HR'])->create();
    $user = User::factory()->create(['name' => 'Kofi Asante']);
    $emp = Employee::factory()->for($user, 'user')->state([
        'department_id' => $dept->id,
    ])->create();

    $out = $this->renderer->render(
        'Hi {{employee.name}}, you are in {{employee.department}}.',
        $emp,
        BroadcastAudienceType::AllActiveEmployees,
    );

    expect($out)->toContain('Kofi Asante');
    expect($out)->toContain('HR');
});

it('renders org_name and today universally', function () {
    config(['app.name' => 'CIHRMS Test']);
    $member = Member::factory()->create();

    $out = $this->renderer->render(
        'From {{org_name}} on {{today}}',
        $member,
        BroadcastAudienceType::AllActiveMembers,
    );

    expect($out)->toStartWith('From CIHRMS Test on ');
});

it('renders unknown vars as empty string (silent skip)', function () {
    $member = Member::factory()->create();

    $out = $this->renderer->render(
        'Hello {{member.name}} {{nonsense.field}} end.',
        $member,
        BroadcastAudienceType::AllActiveMembers,
    );

    expect($out)->toContain('end.');
    expect($out)->not->toContain('{{nonsense.field}}');
});

it('NEVER reads non-whitelisted attributes (e.g. password)', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $out = $this->renderer->render(
        'Hi {{user.name}}, your password is {{user.password}}.',
        $user,
        BroadcastAudienceType::UsersByPermission,
    );

    expect($out)->not->toContain('secret123');
    expect($out)->not->toContain('$2y$');
    expect($out)->toContain('your password is .');
});

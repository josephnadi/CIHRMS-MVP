<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

beforeEach(function () {
    $this->dept = Department::factory()->create();
    $this->user = User::factory()->create(['name' => 'Ama Asante']);
    $this->employee = Employee::factory()->create([
        'user_id'       => $this->user->id,
        'department_id' => $this->dept->id,
        'employee_no'   => 'GH-HR-001',
        'status'        => 'active',
    ]);
});

it('verifies an employee with a matching Staff ID + name fragment', function () {
    $res = $this->postJson(route('kiosk.verify'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Ama',
    ]);

    $res->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('employee.employee_no', 'GH-HR-001')
        ->assertJsonPath('employee.name', 'Ama Asante');
});

it('matches case-insensitively and tolerates extra whitespace', function () {
    $res = $this->postJson(route('kiosk.verify'), [
        'employee_no' => '  gh-hr-001  ',
        'name'        => '  asante  ',
    ]);

    $res->assertOk()->assertJsonPath('ok', true);
});

it('matches against either first OR last name (substring)', function () {
    $res = $this->postJson(route('kiosk.verify'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Asante',
    ]);

    $res->assertOk()->assertJsonPath('ok', true);
});

it('rejects a single-character name to avoid trivial matches', function () {
    $res = $this->postJson(route('kiosk.verify'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'A',
    ]);

    // The 2-char minimum guard returns 422 with ok=false
    $res->assertStatus(422)->assertJsonPath('ok', false);
});

it('returns 422 when the name does not match', function () {
    $res = $this->postJson(route('kiosk.verify'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Kwame',
    ]);

    $res->assertStatus(422)->assertJsonPath('ok', false);
});

it('returns 422 for an unknown Staff ID', function () {
    $res = $this->postJson(route('kiosk.verify'), [
        'employee_no' => 'GH-HR-9999',
        'name'        => 'Ama',
    ]);

    $res->assertStatus(422)->assertJsonPath('ok', false);
});

it('rejects requests missing required fields with 422', function () {
    $this->postJson(route('kiosk.verify'), ['employee_no' => 'GH-HR-001'])
        ->assertStatus(422);

    $this->postJson(route('kiosk.verify'), ['name' => 'Ama'])
        ->assertStatus(422);
});

it('returns suggested_direction = in when no punches today', function () {
    $res = $this->postJson(route('kiosk.verify'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Ama',
    ]);

    $res->assertOk()->assertJsonPath('employee.suggested_direction', 'in');
});

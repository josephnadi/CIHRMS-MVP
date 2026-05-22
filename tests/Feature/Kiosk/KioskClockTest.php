<?php

use App\Enums\AttendanceSource;
use App\Models\AttendanceRecord;
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

it('records a clock-in with source=web_kiosk', function () {
    $res = $this->postJson(route('kiosk.clock'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Ama',
        'direction'   => 'in',
    ]);

    $res->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('record.direction', 'in');

    $this->assertDatabaseHas('attendance_records', [
        'employee_id' => $this->employee->id,
        'direction'   => 'in',
        'source'      => AttendanceSource::WebKiosk->value,
    ]);
});

it('records a clock-out after a clock-in', function () {
    // Seed a prior in-punch so direction=out is valid
    AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'direction'   => 'in',
        'source'      => AttendanceSource::WebKiosk->value,
        'event_at'    => now()->subHours(2),
    ]);

    $res = $this->postJson(route('kiosk.clock'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Ama',
        'direction'   => 'out',
    ]);

    $res->assertOk()->assertJsonPath('record.direction', 'out');
});

it('returns 422 when the name does not match', function () {
    $this->postJson(route('kiosk.clock'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Kwame',
        'direction'   => 'in',
    ])->assertStatus(422)->assertJsonPath('ok', false);

    $this->assertDatabaseCount('attendance_records', 0);
});

it('rejects invalid direction with 422', function () {
    $this->postJson(route('kiosk.clock'), [
        'employee_no' => 'GH-HR-001',
        'name'        => 'Ama',
        'direction'   => 'lunch',
    ])->assertStatus(422);
});

it('rejects missing required fields with 422', function () {
    $this->postJson(route('kiosk.clock'), ['employee_no' => 'GH-HR-001', 'name' => 'Ama'])
        ->assertStatus(422);
});

it('returns 501 on the face-recognition stub', function () {
    $this->postJson(route('kiosk.face'), [])
        ->assertStatus(501)
        ->assertJsonPath('ok', false);
});

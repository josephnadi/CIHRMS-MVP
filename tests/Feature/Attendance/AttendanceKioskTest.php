<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

beforeEach(function () {
    $dept = Department::factory()->create();
    $this->user = User::factory()->create(['name' => 'Akosua Mensah']);
    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id,
        'user_id'       => $this->user->id,
        'employee_no'   => 'GH-HR-821',
        'status'        => 'active',
    ]);
});

it('renders the public kiosk page without auth', function () {
    $this->get('/kiosk')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Kiosk/Index'));
});

it('verifies an employee with matching ID and partial name', function () {
    $this->postJson('/kiosk/verify', [
        'employee_no' => 'GH-HR-821',
        'name'        => 'Akosua',
    ])
        ->assertOk()
        ->assertJson([
            'ok'       => true,
            'employee' => [
                'employee_no'         => 'GH-HR-821',
                'name'                => 'Akosua Mensah',
                'suggested_direction' => 'in',
            ],
        ]);
});

it('rejects verification when name does not match the employee ID', function () {
    $this->postJson('/kiosk/verify', [
        'employee_no' => 'GH-HR-821',
        'name'        => 'Kofi',
    ])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});

it('rejects verification when employee_no is unknown', function () {
    $this->postJson('/kiosk/verify', [
        'employee_no' => 'GH-HR-999',
        'name'        => 'Akosua',
    ])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});

it('records a clock-in and creates a web_kiosk attendance record', function () {
    $this->postJson('/kiosk/clock', [
        'employee_no' => 'GH-HR-821',
        'name'        => 'Akosua',
        'direction'   => 'in',
    ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $record = AttendanceRecord::query()
        ->where('employee_id', $this->employee->id)
        ->latest('event_at')
        ->first();

    expect($record)->not->toBeNull();
    expect($record->direction)->toBe('in');
    expect($record->source)->toBe(AttendanceSource::WebKiosk);
});

it('suggests the opposite direction once an employee has already clocked in today', function () {
    $this->postJson('/kiosk/clock', [
        'employee_no' => 'GH-HR-821',
        'name'        => 'Akosua',
        'direction'   => 'in',
    ])->assertOk();

    $this->postJson('/kiosk/verify', [
        'employee_no' => 'GH-HR-821',
        'name'        => 'Akosua',
    ])
        ->assertOk()
        ->assertJson([
            'employee' => ['suggested_direction' => 'out'],
        ]);
});

it('refuses to record a clock event when the name does not match', function () {
    $this->postJson('/kiosk/clock', [
        'employee_no' => 'GH-HR-821',
        'name'        => 'Someone Else',
        'direction'   => 'in',
    ])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    expect(AttendanceRecord::count())->toBe(0);
});

it('returns 501 from the face endpoint while it is a stub', function () {
    $this->postJson('/kiosk/face', [])
        ->assertStatus(501)
        ->assertJson(['ok' => false]);
});

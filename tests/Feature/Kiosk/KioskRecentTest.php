<?php

use App\Enums\AttendanceSource;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;

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

it('returns the most recent kiosk punches today in descending order', function () {
    AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'direction'   => 'in',
        'source'      => AttendanceSource::WebKiosk->value,
        'event_at'    => now()->setTime(8, 0),
    ]);
    AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'direction'   => 'out',
        'source'      => AttendanceSource::WebKiosk->value,
        'event_at'    => now()->setTime(17, 0),
    ]);

    $res = $this->getJson(route('kiosk.recent'));

    $res->assertOk()
        ->assertJsonCount(2, 'recent')
        ->assertJsonPath('recent.0.direction', 'out')   // most-recent first
        ->assertJsonPath('recent.1.direction', 'in');
});

it('caps the wall at 8 entries', function () {
    foreach (range(1, 12) as $i) {
        AttendanceRecord::factory()->create([
            'employee_id' => $this->employee->id,
            'direction'   => $i % 2 === 0 ? 'out' : 'in',
            'source'      => AttendanceSource::WebKiosk->value,
            'event_at'    => now()->subMinutes(60 - $i),
        ]);
    }

    $this->getJson(route('kiosk.recent'))
        ->assertOk()
        ->assertJsonCount(8, 'recent');
});

it('excludes non-kiosk-source punches from the wall', function () {
    AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'direction'   => 'in',
        'source'      => AttendanceSource::Biometric->value,
        'event_at'    => now(),
    ]);
    AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'direction'   => 'in',
        'source'      => AttendanceSource::GpsMobile->value,
        'event_at'    => now(),
    ]);

    $this->getJson(route('kiosk.recent'))
        ->assertOk()
        ->assertJsonCount(0, 'recent');
});

it('excludes punches from a different day', function () {
    AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'direction'   => 'in',
        'source'      => AttendanceSource::WebKiosk->value,
        'event_at'    => CarbonImmutable::now()->subDay()->setTime(8, 0),
    ]);

    $this->getJson(route('kiosk.recent'))
        ->assertOk()
        ->assertJsonCount(0, 'recent');
});

it('exposes only first name + direction + event_at — no Staff ID or full name', function () {
    AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'direction'   => 'in',
        'source'      => AttendanceSource::WebKiosk->value,
        'event_at'    => now(),
    ]);

    $res = $this->getJson(route('kiosk.recent'));

    $row = $res->json('recent.0');
    expect($row)->toHaveKeys(['first_name', 'direction', 'event_at']);
    expect($row['first_name'])->toBe('Ama');
    // Staff ID + position + last name MUST NOT leak through this endpoint.
    expect($row)->not->toHaveKey('employee_no');
    expect($row)->not->toHaveKey('name');
    expect($row)->not->toHaveKey('position');
});

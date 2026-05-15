<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Services\Attendance\AttendanceService;

it('accepts a clock-in inside the device geofence', function () {
    $emp = Employee::factory()->create();
    $device = BiometricDevice::create([
        'code' => 'GEO-TEST-01', 'name' => 'Geo Test Device', 'vendor' => 'zkteco',
        'shared_secret' => 'test', 'geo_lat' => 5.6037, 'geo_lng' => -0.1870,
        'geo_radius_m' => 100, 'is_active' => true,
    ]);

    $record = app(AttendanceService::class)->record(
        $emp, now(), 'in', AttendanceSource::GpsMobile,
        $device->id, 5.6037, -0.1870
    );

    expect($record)->not->toBeNull();
});

it('rejects a clock-in outside the device geofence radius', function () {
    $emp = Employee::factory()->create();
    $device = BiometricDevice::create([
        'code' => 'GEO-TEST-02', 'name' => 'Geo Test Device 2', 'vendor' => 'zkteco',
        'shared_secret' => 'test', 'geo_lat' => 5.6037, 'geo_lng' => -0.1870,
        'geo_radius_m' => 50, 'is_active' => true,
    ]);

    expect(fn () => app(AttendanceService::class)->record(
        $emp, now(), 'in', AttendanceSource::GpsMobile,
        $device->id, 5.6537, -0.1870
    ))->toThrow(\DomainException::class, 'geofence');
});

it('accepts a clock-in without geofence enforcement when no device is associated', function () {
    $emp = Employee::factory()->create();

    $record = app(AttendanceService::class)->record(
        $emp, now(), 'in', AttendanceSource::WebKiosk,
        null, null, null
    );

    expect($record)->not->toBeNull();
});

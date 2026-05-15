<?php

use App\Models\BiometricDevice;
use App\Models\Department;
use App\Models\Employee;
use App\Models\AttendanceRecord;
use Illuminate\Support\Str;

beforeEach(function () {
    $dept = Department::factory()->create();
    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id,
        'employee_no'   => 'TEST-001',
        'status'        => 'active',
    ]);

    $this->device = BiometricDevice::create([
        'code'          => 'DEV-TEST-01',
        'name'          => 'Test Device',
        'vendor'        => 'zkteco',
        'shared_secret' => $this->secret = Str::random(32),
        'is_active'     => true,
    ]);
});

function signedHeaders(string $secret, string $deviceCode, string $body): array
{
    $ts = (string) time();
    return [
        'X-Device-Code'          => $deviceCode,
        'X-Biometric-Timestamp'  => $ts,
        'X-Biometric-Signature'  => 'sha256='.hash_hmac('sha256', "{$ts}.{$body}", $secret),
        'Content-Type'           => 'application/json',
    ];
}

it('accepts a well-signed webhook and creates attendance records', function () {
    $payload = [
        'device_code' => 'DEV-TEST-01',
        'events' => [
            ['employee_no' => 'TEST-001', 'direction' => 'in',  'event_at' => '2026-06-03T08:00:00+00:00'],
            ['employee_no' => 'TEST-001', 'direction' => 'out', 'event_at' => '2026-06-03T17:00:00+00:00'],
        ],
    ];
    $body = json_encode($payload);

    $resp = $this->withHeaders(signedHeaders($this->secret, 'DEV-TEST-01', $body))
        ->postJson('/webhooks/biometric', $payload);

    $resp->assertOk();
    $resp->assertJson(['accepted' => 2, 'skipped' => 0]);
    expect(AttendanceRecord::where('employee_id', $this->employee->id)->count())->toBe(2);
});

it('rejects an unsigned webhook with 401', function () {
    $resp = $this->postJson('/webhooks/biometric', [
        'device_code' => 'DEV-TEST-01',
        'events' => [['employee_no' => 'TEST-001', 'direction' => 'in', 'event_at' => '2026-06-03T08:00:00+00:00']],
    ]);
    $resp->assertStatus(401);
});

it('rejects a webhook signed with the wrong secret', function () {
    $payload = ['device_code' => 'DEV-TEST-01', 'events' => [
        ['employee_no' => 'TEST-001', 'direction' => 'in', 'event_at' => '2026-06-03T08:00:00+00:00'],
    ]];
    $body = json_encode($payload);

    $resp = $this->withHeaders(signedHeaders('wrong-secret', 'DEV-TEST-01', $body))
        ->postJson('/webhooks/biometric', $payload);

    $resp->assertStatus(401);
    expect(AttendanceRecord::count())->toBe(0);
});

it('rejects a replay older than 5 minutes', function () {
    $payload = ['device_code' => 'DEV-TEST-01', 'events' => [
        ['employee_no' => 'TEST-001', 'direction' => 'in', 'event_at' => '2026-06-03T08:00:00+00:00'],
    ]];
    $body = json_encode($payload);

    $staleTs = (string) (time() - 600);
    $resp = $this->withHeaders([
        'X-Device-Code'          => 'DEV-TEST-01',
        'X-Biometric-Timestamp'  => $staleTs,
        'X-Biometric-Signature'  => 'sha256='.hash_hmac('sha256', "{$staleTs}.{$body}", $this->secret),
    ])->postJson('/webhooks/biometric', $payload);

    $resp->assertStatus(401);
});

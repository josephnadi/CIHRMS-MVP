<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Models\BiometricDevice;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Translates raw device payloads into AttendanceRecord rows.
 *
 * Expected vendor-neutral payload shape (POSTed to /webhooks/biometric):
 * {
 *   "device_code": "DEV-ACCRA-MAIN-01",
 *   "events": [
 *     { "employee_no": "CIHRM-0002", "direction": "in",  "event_at": "2026-05-26T08:03:21+00:00", "score": "98.7" },
 *     { "employee_no": "CIHRM-0002", "direction": "out", "event_at": "2026-05-26T17:14:02+00:00", "score": "99.1" }
 *   ]
 * }
 *
 * Each event maps to one AttendanceRecord; the daily summary is recomputed
 * once per (employee, date) pair touched.
 */
class BiometricIngestionService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * @param array{device_code: string, events: array<int, array<string, mixed>>} $payload
     * @return array{accepted:int, skipped:int, errors:array<int, string>}
     */
    public function ingest(array $payload): array
    {
        $deviceCode = (string) ($payload['device_code'] ?? '');
        $events     = $payload['events'] ?? [];

        $device = BiometricDevice::active()->where('code', $deviceCode)->first();
        if (! $device) {
            return ['accepted' => 0, 'skipped' => 0, 'errors' => ["Unknown or inactive device: {$deviceCode}"]];
        }

        $accepted = 0;
        $skipped  = 0;
        $errors   = [];
        $touchedDays = []; // [employee_id => set of YYYY-MM-DD]

        foreach ($events as $i => $event) {
            try {
                $record = $this->ingestOne($device, $event);
                $accepted++;
                $touchedDays[$record->employee_id][CarbonImmutable::parse($record->event_at)->toDateString()] = true;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "event #{$i}: {$e->getMessage()}";
            }
        }

        $device->update(['last_seen_at' => now()]);

        // Summary recompute is triggered inside AttendanceService::record() already,
        // so $touchedDays is informational only. Kept for tests / observability.
        return ['accepted' => $accepted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function ingestOne(BiometricDevice $device, array $event): \App\Models\AttendanceRecord
    {
        $employeeNo = (string) ($event['employee_no'] ?? '');
        if ($employeeNo === '') {
            throw new \DomainException('Missing employee_no.');
        }

        $employee = Employee::where('employee_no', $employeeNo)->first();
        if (! $employee) {
            throw new \DomainException("Unknown employee_no: {$employeeNo}");
        }

        $direction = strtolower((string) ($event['direction'] ?? ''));
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new \DomainException("Invalid direction: '{$direction}'.");
        }

        $eventAt = $event['event_at'] ?? null;
        if (! $eventAt) {
            throw new \DomainException('Missing event_at.');
        }

        return $this->attendance->record(
            employee:    $employee,
            eventAt:     $eventAt,
            direction:   $direction,
            source:      AttendanceSource::Biometric,
            deviceId:    $device->id,
            geoLat:      isset($event['geo_lat']) ? (float) $event['geo_lat'] : null,
            geoLng:      isset($event['geo_lng']) ? (float) $event['geo_lng'] : null,
            rawPayload:  $event,
        );
    }
}

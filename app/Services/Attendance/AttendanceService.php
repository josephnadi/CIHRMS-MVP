<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Attendance domain service.
 *
 * Responsibilities:
 *   - Record raw clock-in / clock-out events from any source
 *   - Recompute the per-day summary (`attendance_summaries`) from the raw records
 *   - Aggregate monthly stats used by payroll
 *
 * Default schedule (Ghana public service standard):
 *   - Working week: Mon–Fri
 *   - Day window:   08:00 – 17:00
 *   - Lunch:        unpaid 60 min
 *   - Grace:        15 min (clock-in > 08:15 → late)
 *   - Half-day:     hours worked < 4.0
 */
class AttendanceService
{
    public function __construct(private readonly ShiftService $shiftService) {}

    public function record(
        Employee $employee,
        \DateTimeInterface|string $eventAt,
        string $direction,            // 'in' | 'out'
        AttendanceSource $source,
        ?int $deviceId = null,
        ?float $geoLat = null,
        ?float $geoLng = null,
        ?User $recordedBy = null,
        ?string $reason = null,
        ?array $rawPayload = null,
    ): AttendanceRecord {
        $eventAtCarbon = $eventAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($eventAt)
            : CarbonImmutable::parse($eventAt);

        if (! in_array($direction, ['in', 'out'], true)) {
            throw new \InvalidArgumentException("direction must be 'in' or 'out', got '{$direction}'.");
        }

        if ($source->requiresReason() && empty($reason)) {
            throw new \DomainException('Manual attendance entries require a reason for audit.');
        }

        if ($deviceId !== null && $geoLat !== null && $geoLng !== null) {
            $device = \App\Models\BiometricDevice::find($deviceId);
            if ($device && $device->geo_lat !== null && $device->geo_lng !== null && $device->geo_radius_m !== null) {
                $distanceMeters = $this->haversineMeters(
                    (float) $device->geo_lat, (float) $device->geo_lng,
                    $geoLat, $geoLng
                );
                if ($distanceMeters > (int) $device->geo_radius_m) {
                    throw new \DomainException(sprintf(
                        'Clock event %.0fm outside %dm geofence for device %s.',
                        $distanceMeters, $device->geo_radius_m, $device->code
                    ));
                }
            }
        }

        $record = DB::transaction(function () use ($employee, $eventAtCarbon, $direction, $source, $deviceId, $geoLat, $geoLng, $recordedBy, $reason, $rawPayload) {
            return AttendanceRecord::create([
                'employee_id'   => $employee->id,
                'device_id'     => $deviceId,
                'source'        => $source->value,
                'direction'     => $direction,
                'event_at'      => $eventAtCarbon,
                'recorded_at'   => now(),
                'geo_lat'       => $geoLat,
                'geo_lng'       => $geoLng,
                'recorded_by'   => $recordedBy?->id,
                'reason'        => $reason,
                'raw_payload'   => $rawPayload,
            ]);
        });

        // Recompute the summary for the affected day (synchronous — cheap).
        $this->recomputeDailySummary($employee, $eventAtCarbon);

        return $record;
    }

    /**
     * Re-derive `attendance_summaries` for one day from the raw `attendance_records`.
     */
    public function recomputeDailySummary(Employee $employee, \DateTimeInterface|string $date): AttendanceSummary
    {
        // Normalize to midnight UTC so the date-only cast on `summary_date` always
        // serializes to the same string ('YYYY-MM-DD 00:00:00') for both SELECT and
        // INSERT phases of updateOrCreate. Mixing a string ('2026-06-03') with a
        // cast that expands to a datetime causes SQLite to miss the existing row.
        $day = ($date instanceof \DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date))
            ->startOfDay();
        $dayStr = $day->toDateString();

        $isWeekend = $day->isWeekend();
        $isHoliday = PublicHoliday::isHoliday($dayStr);

        /** @var Collection<int, AttendanceRecord> $records */
        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('event_at', $dayStr)
            ->orderBy('event_at')
            ->get();

        $firstIn  = $records->where('direction', 'in')->min('event_at');
        $lastOut  = $records->where('direction', 'out')->max('event_at');

        // Pair in/out events and sum durations. Naive pairing — handles split shifts by summing
        // every (in, next-out) pair in temporal order.
        $hoursWorked = $this->computeHoursWorked($records);

        $status     = $this->deriveStatus($employee, $day, $records, $hoursWorked, $isWeekend, $isHoliday);
        $dominantSource = $records->isNotEmpty() ? (string) $records->first()->source?->value : null;

        $overtime = (new OvertimeCalculator())->calculateForDay(
            hoursWorked: $hoursWorked,
            isWeekend:   $isWeekend,
            isHoliday:   $isHoliday,
        );

        // Pass the Carbon instance (not a string) so the query builder formats
        // SELECT and INSERT identically via its date grammar.
        return AttendanceSummary::updateOrCreate(
            ['employee_id' => $employee->id, 'summary_date' => $day],
            [
                'status'         => $status->value,
                'first_in'       => $firstIn ? CarbonImmutable::parse($firstIn)->format('H:i:s') : null,
                'last_out'       => $lastOut ? CarbonImmutable::parse($lastOut)->format('H:i:s') : null,
                'hours_worked'   => round($hoursWorked, 2),
                'overtime_hours' => round($overtime['total'], 2),
                'is_weekend'     => $isWeekend,
                'is_holiday'     => $isHoliday,
                'source'         => $dominantSource,
            ],
        );
    }

    /**
     * Aggregate a period (typically a month) for one employee, materializing
     * summaries for every day in the range so payroll has a complete picture.
     *
     * @return array{
     *     working_days:int,    days_worked:int,   days_absent:int,
     *     days_late:int,       days_on_leave:int, hours_worked:float,
     *     overtime_hours:float, attendance_ratio:float
     * }
     */
    public function aggregatePeriod(Employee $employee, \DateTimeInterface|string $from, \DateTimeInterface|string $to): array
    {
        $start = $from instanceof \DateTimeInterface ? CarbonImmutable::instance($from) : CarbonImmutable::parse($from);
        $end   = $to   instanceof \DateTimeInterface ? CarbonImmutable::instance($to)   : CarbonImmutable::parse($to);

        // Materialize a summary for every day so absences are explicit, not implicit.
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $this->ensureSummaryForDay($employee, CarbonImmutable::instance($day));
        }

        $summaries = AttendanceSummary::where('employee_id', $employee->id)
            ->between($start, $end)
            ->get();

        $workingDays = $summaries->reject(fn ($s) => $s->status?->isExcused())->count();
        $worked      = $summaries->filter(fn ($s) => $s->status?->isWorked())->count();
        $absent      = $summaries->where('status.value', AttendanceStatus::Absent->value)->count();
        $late        = $summaries->where('status.value', AttendanceStatus::Late->value)->count();
        $onLeave     = $summaries->where('status.value', AttendanceStatus::OnLeave->value)->count();
        $hours       = (float) $summaries->sum('hours_worked');
        $overtime    = (float) $summaries->sum('overtime_hours');

        return [
            'working_days'     => $workingDays,
            'days_worked'      => $worked,
            'days_absent'      => $absent,
            'days_late'        => $late,
            'days_on_leave'    => $onLeave,
            'hours_worked'     => round($hours, 2),
            'overtime_hours'   => round($overtime, 2),
            'attendance_ratio' => $workingDays > 0 ? round($worked / $workingDays, 4) : 0.0,
        ];
    }

    private function ensureSummaryForDay(Employee $employee, CarbonImmutable $day): void
    {
        $exists = AttendanceSummary::where('employee_id', $employee->id)
            ->whereDate('summary_date', $day->toDateString())
            ->exists();

        if (! $exists) {
            $this->recomputeDailySummary($employee, $day);
        }
    }

    private function deriveStatus(
        Employee $employee,
        CarbonImmutable $day,
        Collection $records,
        float $hoursWorked,
        bool $isWeekend,
        bool $isHoliday,
    ): AttendanceStatus {
        if ($isHoliday) return AttendanceStatus::Holiday;

        $schedule = $this->shiftService->scheduleFor($employee, $day);

        $isNonWorkingDay = ! in_array(
            strtolower(Carbon::parse($day)->englishDayOfWeek),
            $schedule['working_days'],
            true
        );

        if ($isNonWorkingDay) return AttendanceStatus::Weekend;

        // Check for approved leave covering this day
        if ($this->isOnApprovedLeave($employee, $day)) {
            return AttendanceStatus::OnLeave;
        }

        if ($records->isEmpty() || $hoursWorked <= 0) {
            return AttendanceStatus::Absent;
        }

        if ($hoursWorked < $schedule['half_day_hours']) {
            return AttendanceStatus::HalfDay;
        }

        $firstIn = $records->where('direction', 'in')->min('event_at');
        if ($firstIn) {
            $lateThreshold = $day->setTimeFromTimeString(
                Carbon::parse($schedule['start_time'])->addMinutes($schedule['grace_period_minutes'])->format('H:i')
            );
            if (CarbonImmutable::parse($firstIn)->greaterThan($lateThreshold)) {
                return AttendanceStatus::Late;
            }
        }

        return AttendanceStatus::Present;
    }

    private function isOnApprovedLeave(Employee $employee, CarbonImmutable $day): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date',   '>=', $day->toDateString())
            ->exists();
    }

    private function computeHoursWorked(Collection $records): float
    {
        // Pair in/out events sequentially. Unmatched in's are dropped silently
        // (no out → assume forgot-to-clock-out, conservative for payroll).
        $stack = [];
        $totalSeconds = 0;

        foreach ($records as $r) {
            if ($r->direction === 'in') {
                $stack[] = $r->event_at;
            } elseif ($r->direction === 'out' && ! empty($stack)) {
                $in = array_pop($stack);
                $totalSeconds += CarbonImmutable::parse($in)->diffInSeconds($r->event_at);
            }
        }

        return round($totalSeconds / 3600, 2);
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

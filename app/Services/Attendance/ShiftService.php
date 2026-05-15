<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;

class ShiftService
{
    public const DEFAULT_SCHEDULE = [
        'start_time'           => '08:00',
        'end_time'             => '17:00',
        'grace_period_minutes' => 15,
        'full_day_hours'       => 8.0,
        'half_day_hours'       => 4.0,
        'working_days'         => ['mon','tue','wed','thu','fri'],
    ];

    public function scheduleFor(Employee $employee, CarbonImmutable $date): array
    {
        $assignment = ShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->with('shift')
            ->latest('effective_from')
            ->first();

        if (! $assignment || ! $assignment->shift?->is_active) {
            return self::DEFAULT_SCHEDULE;
        }

        $shift = $assignment->shift;

        return [
            'start_time'           => substr((string) $shift->start_time, 0, 5),
            'end_time'             => substr((string) $shift->end_time, 0, 5),
            'grace_period_minutes' => (int) $shift->grace_period_minutes,
            'full_day_hours'       => (float) $shift->full_day_hours,
            'half_day_hours'       => (float) $shift->half_day_hours,
            'working_days'         => $shift->working_days ?: self::DEFAULT_SCHEDULE['working_days'],
        ];
    }
}

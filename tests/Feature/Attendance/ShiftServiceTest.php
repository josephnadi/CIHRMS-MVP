<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Attendance\ShiftService;
use Carbon\CarbonImmutable;

it('returns the default Ghana public-service schedule when no assignment exists', function () {
    $emp = Employee::factory()->create();
    $schedule = app(ShiftService::class)->scheduleFor($emp, CarbonImmutable::parse('2026-06-15'));

    expect($schedule['start_time'])->toBe('08:00')
        ->and($schedule['end_time'])->toBe('17:00')
        ->and($schedule['grace_period_minutes'])->toBe(15)
        ->and($schedule['full_day_hours'])->toBe(8.0)
        ->and($schedule['half_day_hours'])->toBe(4.0)
        ->and($schedule['working_days'])->toBe(['mon','tue','wed','thu','fri']);
});

it('returns the assigned shift when an active assignment covers the date', function () {
    $emp = Employee::factory()->create();
    $shift = Shift::create([
        'code' => 'NIGHT', 'name' => 'Night Shift',
        'start_time' => '22:00', 'end_time' => '06:00',
        'grace_period_minutes' => 10, 'full_day_hours' => 8.0, 'half_day_hours' => 4.0,
        'is_active' => true,
    ]);
    ShiftAssignment::create([
        'employee_id'    => $emp->id,
        'shift_id'       => $shift->id,
        'effective_from' => '2026-06-01',
        'effective_to'   => null,
    ]);

    $schedule = app(ShiftService::class)->scheduleFor($emp, CarbonImmutable::parse('2026-06-15'));

    expect($schedule['start_time'])->toBe('22:00')
        ->and($schedule['end_time'])->toBe('06:00')
        ->and($schedule['grace_period_minutes'])->toBe(10);
});

it('falls back to default when the assignment has expired', function () {
    $emp = Employee::factory()->create();
    $shift = Shift::create([
        'code' => 'OLD', 'name' => 'Old Shift',
        'start_time' => '06:00', 'end_time' => '14:00',
        'grace_period_minutes' => 5, 'full_day_hours' => 8.0, 'half_day_hours' => 4.0,
        'is_active' => true,
    ]);
    ShiftAssignment::create([
        'employee_id'    => $emp->id,
        'shift_id'       => $shift->id,
        'effective_from' => '2026-01-01',
        'effective_to'   => '2026-05-31',
    ]);

    $schedule = app(ShiftService::class)->scheduleFor($emp, CarbonImmutable::parse('2026-06-15'));

    expect($schedule['start_time'])->toBe('08:00');
});

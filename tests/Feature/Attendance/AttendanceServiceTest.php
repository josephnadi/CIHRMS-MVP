<?php

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Services\Attendance\AttendanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\GhanaPublicHolidaySeeder;

beforeEach(function () {
    $this->svc = app(AttendanceService::class);

    $dept = Department::factory()->create();
    $this->employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
});

it('marks a normal 8h clock-in/out day as present', function () {
    // Pick a Wednesday for determinism (no weekend)
    $day = CarbonImmutable::parse('2026-06-03'); // Wednesday

    $this->svc->record($this->employee, $day->setTime(8, 0),  'in',  AttendanceSource::Biometric);
    $this->svc->record($this->employee, $day->setTime(17, 0), 'out', AttendanceSource::Biometric);

    $s = $this->svc->recomputeDailySummary($this->employee, $day);

    expect($s->status)->toBe(AttendanceStatus::Present);
    expect((float) $s->hours_worked)->toEqualWithDelta(9.0, 0.01);
    expect((float) $s->overtime_hours)->toBeFloat();
});

it('marks a late clock-in (after 08:15) as Late', function () {
    $day = CarbonImmutable::parse('2026-06-03');

    $this->svc->record($this->employee, $day->setTime(8, 30), 'in',  AttendanceSource::Biometric);
    $this->svc->record($this->employee, $day->setTime(17, 0), 'out', AttendanceSource::Biometric);

    $s = $this->svc->recomputeDailySummary($this->employee, $day);

    expect($s->status)->toBe(AttendanceStatus::Late);
});

it('marks a less-than-4h day as half_day', function () {
    $day = CarbonImmutable::parse('2026-06-03');

    $this->svc->record($this->employee, $day->setTime(8, 0),  'in',  AttendanceSource::Biometric);
    $this->svc->record($this->employee, $day->setTime(11, 0), 'out', AttendanceSource::Biometric);

    $s = $this->svc->recomputeDailySummary($this->employee, $day);

    expect($s->status)->toBe(AttendanceStatus::HalfDay);
    expect((float) $s->hours_worked)->toEqualWithDelta(3.0, 0.01);
});

it('marks a day with no records as Absent', function () {
    $day = CarbonImmutable::parse('2026-06-03');
    $s = $this->svc->recomputeDailySummary($this->employee, $day);
    expect($s->status)->toBe(AttendanceStatus::Absent);
});

it('marks a Saturday as Weekend', function () {
    $sat = CarbonImmutable::parse('2026-06-06'); // Saturday
    $s = $this->svc->recomputeDailySummary($this->employee, $sat);
    expect($s->status)->toBe(AttendanceStatus::Weekend);
});

it('marks a Ghana public holiday as Holiday', function () {
    $this->seed(GhanaPublicHolidaySeeder::class);
    // 6 March 2026 is Independence Day in the seeder
    $hol = CarbonImmutable::parse('2026-03-06');
    $s = $this->svc->recomputeDailySummary($this->employee, $hol);
    expect($s->status)->toBe(AttendanceStatus::Holiday);
});

it('refuses manual entries without a reason', function () {
    expect(fn () => $this->svc->record(
        $this->employee,
        '2026-06-03 08:00',
        'in',
        AttendanceSource::Manual,
    ))->toThrow(\DomainException::class, 'require a reason');
});

it('aggregates a period and reports days_worked correctly', function () {
    $this->seed(GhanaPublicHolidaySeeder::class);

    // Two clean working days
    foreach (['2026-06-01', '2026-06-02'] as $d) {  // Mon, Tue
        $day = CarbonImmutable::parse($d);
        $this->svc->record($this->employee, $day->setTime(8, 0),  'in',  AttendanceSource::Biometric);
        $this->svc->record($this->employee, $day->setTime(17, 0), 'out', AttendanceSource::Biometric);
    }

    $agg = $this->svc->aggregatePeriod($this->employee, '2026-06-01', '2026-06-07');

    expect($agg['days_worked'])->toBe(2);
    // 7 days total - 2 weekend days = 5 working days
    expect($agg['working_days'])->toBe(5);
    expect($agg['attendance_ratio'])->toEqualWithDelta(0.4, 0.0001);
});

<?php

namespace Database\Factories;

use App\Enums\AttendanceSource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        $eventAt = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'employee_id'    => Employee::factory(),
            'device_id'      => null,
            'source'         => $this->faker->randomElement([
                AttendanceSource::WebKiosk->value,
                AttendanceSource::Biometric->value,
                AttendanceSource::GpsMobile->value,
            ]),
            'direction'      => $this->faker->randomElement(['in', 'out']),
            'event_at'       => $eventAt,
            'recorded_at'    => $eventAt,
            'geo_lat'        => null,
            'geo_lng'        => null,
            'biometric_score'=> null,
            'recorded_by'    => null,
            'reason'         => null,
            'raw_payload'    => null,
        ];
    }
}

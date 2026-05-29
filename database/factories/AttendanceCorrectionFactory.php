<?php

namespace Database\Factories;

use App\Enums\CorrectionStatus;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceCorrection>
 */
class AttendanceCorrectionFactory extends Factory
{
    protected $model = AttendanceCorrection::class;

    public function definition(): array
    {
        return [
            'employee_id'           => Employee::factory(),
            'requester_id'          => User::factory(),
            'attendance_record_id'  => null,
            'requested_event_at'    => $this->faker->dateTimeBetween('-30 days', 'now'),
            'requested_direction'   => $this->faker->randomElement(['in', 'out']),
            'reason'                => $this->faker->sentence(),
            'status'                => CorrectionStatus::Pending,
            'reviewer_id'           => null,
            'reviewed_at'           => null,
            'decision_notes'        => null,
        ];
    }
}

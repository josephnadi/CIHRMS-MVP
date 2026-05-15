<?php

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-3 months', '+1 month');
        $end   = (clone $start)->modify('+' . fake()->numberBetween(1, 14) . ' days');

        return [
            'employee_id' => Employee::factory(),
            'approved_by' => null,
            'start_date'  => $start->format('Y-m-d'),
            'end_date'    => $end->format('Y-m-d'),
            'type'        => fake()->randomElement(LeaveType::cases())->value,
            'reason'      => fake()->sentence(10),
            'status'      => fake()->randomElement([
                LeaveStatus::Pending, LeaveStatus::Approved, LeaveStatus::Approved,
                LeaveStatus::Rejected,
            ])->value,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => LeaveStatus::Pending->value, 'approved_by' => null]);
    }

    public function approved(): static
    {
        return $this->state(['status' => LeaveStatus::Approved->value]);
    }
}

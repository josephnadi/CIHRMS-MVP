<?php

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'user_id'       => User::factory(),
            'employee_no'   => 'CIHRM-' . str_pad((string) fake()->unique()->numberBetween(1000, 9999), 4, '0', STR_PAD_LEFT),
            'position'      => fake()->randomElement([
                'Software Engineer', 'Senior Engineer', 'Product Manager', 'HR Generalist',
                'Accountant', 'Operations Lead', 'Designer', 'Data Analyst',
                'Marketing Specialist', 'Customer Success Manager',
            ]),
            'hire_date'     => fake()->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
            'phone'         => '+2332' . fake()->numerify('########'),
            'status'        => fake()->randomElement([
                EmployeeStatus::Active, EmployeeStatus::Active, EmployeeStatus::Active,
                EmployeeStatus::OnLeave, EmployeeStatus::Inactive,
            ])->value,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => EmployeeStatus::Active->value]);
    }

    public function onLeave(): static
    {
        return $this->state(['status' => EmployeeStatus::OnLeave->value]);
    }

    public function terminated(): static
    {
        return $this->state(['status' => EmployeeStatus::Terminated->value]);
    }
}

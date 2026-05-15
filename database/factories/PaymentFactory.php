<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Employee;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $status = fake()->randomElement([
            PaymentStatus::Paid, PaymentStatus::Paid, PaymentStatus::Paid,
            PaymentStatus::Pending,
        ]);

        return [
            'employee_id'  => Employee::factory(),
            'processed_by' => null,
            'description'  => fake()->randomElement([
                'Monthly Salary',
                'Quarterly Bonus',
                'Project Completion Bonus',
                'Overtime Compensation',
                'Travel Reimbursement',
            ]),
            'amount'       => fake()->randomFloat(2, 1500, 12000),
            'currency'     => 'GHS',
            'status'       => $status->value,
            'paid_at'      => $status === PaymentStatus::Paid
                ? fake()->dateTimeBetween('-6 months', 'now')
                : null,
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status'  => PaymentStatus::Paid->value,
            'paid_at' => fake()->dateTimeBetween('-3 months', 'now'),
        ]);
    }

    public function pending(): static
    {
        return $this->state(['status' => PaymentStatus::Pending->value, 'paid_at' => null]);
    }
}

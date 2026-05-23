<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentIntentStatus;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    public function definition(): array
    {
        return [
            'reference'   => fake()->unique()->bothify('PI-2026-######'),
            'customer_id' => Customer::factory(),
            'amount'      => fake()->randomFloat(2, 10, 5000),
            'currency'    => 'GHS',
            'status'      => PaymentIntentStatus::Created->value,
            'created_by'  => User::factory(),
        ];
    }
}

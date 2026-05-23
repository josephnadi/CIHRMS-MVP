<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'code'   => fake()->unique()->bothify('CUS-####'),
            'name'   => fake()->company(),
            'tax_id' => fake()->bothify('GH-TIN-######'),
            'status' => CustomerStatus::Active->value,
            'email'  => fake()->companyEmail(),
            'phone'  => fake()->phoneNumber(),
        ];
    }
}

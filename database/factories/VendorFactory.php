<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VendorStatus;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'code'   => fake()->unique()->bothify('VEN-####'),
            'name'   => fake()->company(),
            'tax_id' => fake()->bothify('GH-TIN-######'),
            'status' => VendorStatus::Active->value,
            'email'  => fake()->companyEmail(),
            'phone'  => fake()->phoneNumber(),
        ];
    }
}

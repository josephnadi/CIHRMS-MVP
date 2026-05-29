<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetAssignment>
 */
class AssetAssignmentFactory extends Factory
{
    protected $model = AssetAssignment::class;

    public function definition(): array
    {
        return [
            'asset_id'    => Asset::factory(),
            'employee_id' => Employee::factory(),
            'assigned_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'assigned_by' => User::factory(),
            'due_back_at' => fake()->optional()->dateTimeBetween('+1 month', '+1 year')?->format('Y-m-d'),
            'returned_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Engineering', 'Operations', 'Finance', 'Marketing', 'Sales',
            'Customer Support', 'Legal', 'Procurement', 'Research', 'Communications',
        ]);

        return [
            'name'        => $name,
            'code'        => strtoupper(Str::substr(Str::slug($name, ''), 0, 4)) . fake()->unique()->numberBetween(10, 99),
            'description' => fake()->sentence(8),
        ];
    }
}

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
        $candidates = [
            'Engineering', 'Operations', 'Finance', 'Marketing', 'Sales',
            'Customer Support', 'Legal', 'Procurement', 'Research', 'Communications',
        ];

        // Exclude names already in the DB so the factory can run after seeders
        // that pre-create canonical departments (Marketing, Finance, IT, …) via
        // firstOrCreate — otherwise the random pick collides on departments.name UNIQUE.
        $taken = Department::query()->pluck('name')->all();
        $pool  = array_values(array_diff($candidates, $taken));

        $name = ! empty($pool)
            ? fake()->unique()->randomElement($pool)
            : 'Dept ' . fake()->unique()->numberBetween(100, 999);

        return [
            'name'        => $name,
            'code'        => strtoupper(Str::substr(Str::slug($name, ''), 0, 4)) . fake()->unique()->numberBetween(10, 99),
            'description' => fake()->sentence(8),
        ];
    }
}

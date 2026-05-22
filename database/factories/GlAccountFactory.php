<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GlAccount>
 */
class GlAccountFactory extends Factory
{
    protected $model = GlAccount::class;

    public function definition(): array
    {
        return [
            'code'      => fake()->unique()->numerify('####'),
            'name'      => fake()->sentence(3),
            'type'      => fake()->randomElement(GlAccountType::cases())->value,
            'is_active' => true,
            'currency'  => 'GHS',
        ];
    }
}

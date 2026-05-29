<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BenefitType;
use App\Models\BenefitPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitPlan>
 */
class BenefitPlanFactory extends Factory
{
    protected $model = BenefitPlan::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'name'                              => fake()->words(3, true) . ' Plan',
            'code'                              => 'BPLAN-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'type'                              => BenefitType::HealthInsurance->value,
            'provider'                          => fake()->company(),
            'description'                       => fake()->sentence(),
            'monthly_cost'                      => fake()->randomFloat(2, 50, 500),
            'employee_contribution_percentage'  => 0,
            'is_active'                         => true,
            'effective_from'                    => now()->subYear()->format('Y-m-d'),
            'effective_to'                      => null,
            'max_dependants'                    => 0,
            'cover_details'                     => null,
        ];
    }
}

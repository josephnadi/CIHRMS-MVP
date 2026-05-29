<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BenefitEnrolmentStatus;
use App\Models\BenefitEnrolment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitEnrolment>
 */
class BenefitEnrolmentFactory extends Factory
{
    protected $model = BenefitEnrolment::class;

    public function definition(): array
    {
        return [
            'plan_id'          => BenefitPlan::factory(),
            'employee_id'      => Employee::factory(),
            'enrolled_at'      => now()->subMonths(6)->format('Y-m-d'),
            'effective_from'   => now()->subMonths(6)->format('Y-m-d'),
            'effective_to'     => null,
            'status'           => BenefitEnrolmentStatus::Active->value,
            'monthly_premium'  => fake()->randomFloat(2, 20, 200),
            'notes'            => null,
        ];
    }
}

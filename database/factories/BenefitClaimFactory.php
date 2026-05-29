<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClaimStatus;
use App\Models\BenefitClaim;
use App\Models\BenefitEnrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitClaim>
 */
class BenefitClaimFactory extends Factory
{
    protected $model = BenefitClaim::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'enrolment_id'   => BenefitEnrolment::factory(),
            'claim_reference' => sprintf('CLM-%04d-%05d', now()->year, $seq),
            'amount'         => fake()->randomFloat(2, 50, 5000),
            'currency'       => 'GHS',
            'claim_date'     => now()->subDays(fake()->numberBetween(1, 30))->format('Y-m-d'),
            'description'    => fake()->sentence(),
            'status'         => ClaimStatus::Submitted->value,
            'submitted_at'   => now()->subDays(fake()->numberBetween(1, 10)),
            'decision_at'    => null,
            'decision_notes' => null,
            'decided_by'     => null,
        ];
    }
}

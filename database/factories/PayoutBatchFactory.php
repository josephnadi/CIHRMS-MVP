<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutBatchStatus;
use App\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutBatchFactory extends Factory
{
    protected $model = PayoutBatch::class;

    public function definition(): array
    {
        return [
            'reference'              => fake()->unique()->bothify('POUT-2026-######'),
            'status'                 => PayoutBatchStatus::PendingRelease->value,
            'total_amount'           => 0,
            'currency'               => 'GHS',
            'requires_high_approval' => false,
            'created_by'             => User::factory(),
        ];
    }
}

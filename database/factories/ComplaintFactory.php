<?php

namespace Database\Factories;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    public function definition(): array
    {
        return [
            'reference'    => 'CMP-' . strtoupper(fake()->bothify('?####')),
            'submitted_by' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'details'      => fake()->paragraph(4),
            'status'       => fake()->randomElement([
                ComplaintStatus::Open, ComplaintStatus::Open,
                ComplaintStatus::UnderReview, ComplaintStatus::Resolved,
                ComplaintStatus::Closed,
            ])->value,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    protected $model = JobPosting::class;

    public function definition(): array
    {
        return [
            'title'       => fake()->randomElement([
                'Senior Backend Engineer',
                'Frontend Developer (Vue.js)',
                'HR Business Partner',
                'Financial Analyst',
                'Marketing Coordinator',
                'Operations Manager',
                'Customer Success Specialist',
                'Compliance Officer',
            ]),
            'description' => fake()->paragraphs(3, true),
            'closes_at'   => fake()->dateTimeBetween('+1 week', '+3 months')->format('Y-m-d'),
            'status'      => fake()->randomElement([
                JobPostingStatus::Open, JobPostingStatus::Open, JobPostingStatus::Open,
                JobPostingStatus::Draft, JobPostingStatus::Closed,
            ])->value,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => JobPostingStatus::Open->value]);
    }

    public function closed(): static
    {
        return $this->state([
            'status'    => JobPostingStatus::Closed->value,
            'closes_at' => fake()->dateTimeBetween('-2 months', '-1 day')->format('Y-m-d'),
        ]);
    }
}

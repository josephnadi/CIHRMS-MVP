<?php

namespace Database\Factories;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    protected $model = Applicant::class;

    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'name'           => fake()->name(),
            'email'          => fake()->unique()->safeEmail(),
            'cv_path'        => null,
            'status'         => fake()->randomElement([
                ApplicantStatus::Applied, ApplicantStatus::Applied, ApplicantStatus::Applied,
                ApplicantStatus::Shortlisted, ApplicantStatus::Interviewed,
                ApplicantStatus::Offered, ApplicantStatus::Rejected,
            ])->value,
        ];
    }

    public function shortlisted(): static
    {
        return $this->state(['status' => ApplicantStatus::Shortlisted->value]);
    }

    public function hired(): static
    {
        return $this->state(['status' => ApplicantStatus::Hired->value]);
    }
}

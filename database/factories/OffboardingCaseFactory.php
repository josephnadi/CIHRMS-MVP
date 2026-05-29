<?php

namespace Database\Factories;

use App\Enums\ExitType;
use App\Enums\OffboardingStatus;
use App\Models\Employee;
use App\Models\OffboardingCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffboardingCase>
 */
class OffboardingCaseFactory extends Factory
{
    protected $model = OffboardingCase::class;

    public function definition(): array
    {
        $noticeDate   = fake()->dateTimeBetween('-3 months', '-1 month');
        $lastWorking  = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'reference'                  => 'OFF-' . date('Y') . '-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'employee_id'                => Employee::factory(),
            'initiated_by'               => null,
            'exit_type'                  => fake()->randomElement(ExitType::cases())->value,
            'status'                     => OffboardingStatus::InProgress->value,
            'notice_received_on'         => $noticeDate->format('Y-m-d'),
            'last_working_day'           => $lastWorking->format('Y-m-d'),
            'effective_termination_date' => $lastWorking->format('Y-m-d'),
            'rehire_eligible'            => true,
            'reason'                     => null,
        ];
    }
}

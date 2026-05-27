<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Customer;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Member. Creates a linked Customer on the fly so tests can
 * `Member::factory()->create()` and the AR pipeline works end-to-end.
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        $name  = fake()->name();
        $year  = now()->year;
        $seq   = str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT);
        $memNo = "CIHRM-M-{$year}-{$seq}";

        return [
            'member_no'              => $memNo,
            'class'                  => MemberClass::Professional->value,
            'status'                 => MemberStatus::Active->value,
            'name'                   => $name,
            'email'                  => fake()->unique()->safeEmail(),
            'phone'                  => '+233' . fake()->numerify('2########'),
            'address'                => fake()->address(),
            'date_of_birth'          => fake()->dateTimeBetween('-60 years', '-22 years')->format('Y-m-d'),
            'ghana_card_number_hash' => hash('sha256', 'GHA-' . fake()->numerify('#########-#')),
            'customer_id'            => Customer::factory()->state(fn () => ['code' => $memNo, 'name' => $name]),
            'chartered_at'           => fake()->dateTimeBetween('-10 years', '-1 year'),
            'notes'                  => null,
        ];
    }

    public function student(): static
    {
        return $this->state(fn () => [
            'class'        => MemberClass::Student->value,
            'chartered_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => MemberStatus::Suspended->value]);
    }
}

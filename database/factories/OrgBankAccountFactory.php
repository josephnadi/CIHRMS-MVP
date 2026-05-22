<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrgBankAccount>
 */
class OrgBankAccountFactory extends Factory
{
    protected $model = OrgBankAccount::class;

    public function definition(): array
    {
        return [
            'gl_account_id'   => GlAccount::factory()->state(['type' => 'asset']),
            'bank_name'       => fake()->company() . ' Bank',
            'branch'          => fake()->city(),
            'account_name'    => fake()->company(),
            'account_number'  => fake()->unique()->numerify('##########'),
            'sort_code'       => fake()->bothify('GH######'),
            'currency'        => 'GHS',
            'purpose'         => fake()->randomElement(OrgBankAccountPurpose::cases())->value,
            'opening_balance' => 0,
            'is_active'       => true,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArReceiptStatus;
use App\Models\ArReceipt;
use App\Models\Customer;
use App\Models\OrgBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArReceipt>
 */
class ArReceiptFactory extends Factory
{
    protected $model = ArReceipt::class;

    public function definition(): array
    {
        return [
            'reference'           => fake()->unique()->bothify('ARC-2026-####'),
            'customer_id'         => Customer::factory(),
            'status'              => ArReceiptStatus::Pending->value,
            'receipt_date'        => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'amount'              => fake()->randomFloat(2, 100, 10_000),
            'currency'            => 'GHS',
            'org_bank_account_id' => OrgBankAccount::factory(),
            'external_ref'        => null,
            'narration'           => fake()->sentence(),
            'created_by'          => User::factory(),
        ];
    }
}

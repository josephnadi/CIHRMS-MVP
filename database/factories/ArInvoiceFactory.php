<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArInvoiceStatus;
use App\Enums\GlAccountType;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArInvoice>
 */
class ArInvoiceFactory extends Factory
{
    protected $model = ArInvoice::class;

    public function definition(): array
    {
        return [
            'reference'     => fake()->unique()->bothify('ARI-2026-####'),
            'customer_id'   => Customer::factory(),
            'status'        => ArInvoiceStatus::Draft->value,
            'invoice_date'  => fake()->dateTimeBetween('-90 days', 'now')->format('Y-m-d'),
            'due_date'      => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'subtotal'      => 0,
            'tax_amount'    => 0,
            'total'         => 0,
            'amount_received'=> 0,
            'currency'      => 'GHS',
            'ar_gl_account_id' => GlAccount::where('type', GlAccountType::Asset->value)
                ->orderBy('id')->value('id')
                ?? GlAccount::factory(),
            'created_by'    => User::factory(),
        ];
    }
}

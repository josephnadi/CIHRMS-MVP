<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IncomingInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomingInvoiceFactory extends Factory
{
    protected $model = IncomingInvoice::class;

    public function definition(): array
    {
        return [
            'reference'    => 'INV-' . fake()->unique()->numerify('######'),
            'status'       => 'draft',
            'vendor_name'  => fake()->company(),
            'vendor_invoice_no' => fake()->bothify('BILL-####'),
            'invoice_date' => '2026-07-09',
            'currency'     => 'GHS',
            'amount'       => fake()->randomFloat(2, 50, 5000),
            'description'  => fake()->sentence(),
            'created_by'   => User::factory(),
        ];
    }
}

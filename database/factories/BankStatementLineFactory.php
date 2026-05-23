<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankStatementLine>
 */
class BankStatementLineFactory extends Factory
{
    protected $model = BankStatementLine::class;

    public function definition(): array
    {
        return [
            'bank_statement_id' => BankStatement::factory(),
            'line_no'           => fake()->numberBetween(1, 999),
            'transaction_date'  => now()->format('Y-m-d'),
            'description'       => fake()->sentence(4),
            'reference'         => fake()->optional()->bothify('REF-#########'),
            'amount'            => fake()->randomFloat(2, -1000, 1000),
            'line_hash'         => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}

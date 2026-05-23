<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankStatement;
use App\Models\OrgBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankStatement>
 */
class BankStatementFactory extends Factory
{
    protected $model = BankStatement::class;

    public function definition(): array
    {
        return [
            'org_bank_account_id' => OrgBankAccount::factory(),
            'statement_date'      => now()->format('Y-m-d'),
            'opening_balance'     => 0,
            'closing_balance'     => fake()->randomFloat(2, -1000, 5000),
            'currency'            => 'GHS',
            'file_hash'           => hash('sha256', fake()->unique()->uuid()),
            'file_name'           => fake()->unique()->bothify('stmt-####.csv'),
            'format'              => 'csv',
            'imported_by'         => User::factory(),
        ];
    }
}

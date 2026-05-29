<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AmortizationMethod;
use App\Enums\LoanStatus;
use App\Models\Employee;
use App\Models\LoanAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanAccount>
 */
class LoanAccountFactory extends Factory
{
    protected $model = LoanAccount::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;
        $principal = round(fake()->randomFloat(2, 1_000, 20_000), 2);

        return [
            'reference'                  => sprintf('LOAN-%04d-%05d', now()->year, $seq),
            'employee_id'                => Employee::factory(),
            'product_id'                 => null,
            'status'                     => LoanStatus::PendingApproval->value,
            'principal'                  => $principal,
            'term_months'                => fake()->randomElement([6, 12, 24]),
            'booked_interest_rate'       => 0.12,
            'booked_amortization_method' => AmortizationMethod::ReducingBalance->value,
            'monthly_installment'        => round($principal / 12, 2),
            'total_interest'             => round($principal * 0.06, 2),
            'total_repayable'            => round($principal * 1.06, 2),
            'outstanding_balance'        => round($principal * 1.06, 2),
            'disbursed_amount'           => 0,
            'installments_paid'          => 0,
        ];
    }
}

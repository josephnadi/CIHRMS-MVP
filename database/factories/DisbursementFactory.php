<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Disbursement>
 *
 * Defaults produce a standalone disbursement (no payroll_run_id/payroll_line_id)
 * since disbursements.payroll_run_id/payroll_line_id are nullable — settlement
 * and ad-hoc payout rows (e.g. Hubtel bank payouts) are not tied to a payroll
 * line. Pass explicit ids in tests that need a payroll-linked row.
 */
class DisbursementFactory extends Factory
{
    protected $model = Disbursement::class;

    public function definition(): array
    {
        $gross = fake()->randomFloat(2, 500, 5000);

        return [
            'payroll_run_id'      => null,
            'payroll_line_id'     => null,
            'employee_id'         => Employee::factory(),
            'final_settlement_id' => null,
            'channel'             => DisbursementChannel::GhipssAch->value,
            'status'              => DisbursementStatus::Pending->value,
            'gross_amount'        => $gross,
            'e_levy'              => 0,
            'provider_fee'        => 0,
            'net_to_recipient'    => $gross,
            'beneficiary_account' => fake()->numerify('0#########'),
            'beneficiary_name'    => fake()->name(),
            'provider_reference'  => null,
            'provider_response'   => null,
            'retry_count'         => 0,
        ];
    }
}

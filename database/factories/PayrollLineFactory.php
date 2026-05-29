<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollLine>
 */
class PayrollLineFactory extends Factory
{
    protected $model = PayrollLine::class;

    public function definition(): array
    {
        $basic = round(fake()->randomFloat(2, 800, 5_000), 2);
        $gross = round($basic * 1.2, 2);
        $paye  = round($gross * 0.1, 2);
        $net   = round($gross - $paye, 2);

        return [
            'payroll_run_id'         => PayrollRun::factory(),
            'employee_id'            => Employee::factory(),
            'basic'                  => $basic,
            'allowance_total'        => round($gross - $basic, 2),
            'gross'                  => $gross,
            'ssnit_base'             => $gross,
            'ssnit_tier1_employee'   => round($gross * 0.055, 2),
            'ssnit_tier1_employer'   => round($gross * 0.13, 2),
            'nhia_split'             => round($gross * 0.025, 2),
            'tier2_employer'         => round($gross * 0.05, 2),
            'tier3_employee'         => 0,
            'paye'                   => $paye,
            'voluntary_deductions'   => 0,
            'net'                    => $net,
            'status'                 => 'calculated',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        $year  = now()->year;
        $month = now()->month;

        return [
            'period_year'   => $year,
            'period_month'  => $month,
            'period_start'  => sprintf('%04d-%02d-01', $year, $month),
            'period_end'    => sprintf('%04d-%02d-28', $year, $month),
            'status'        => PayrollRunStatus::Draft->value,
            'created_by'    => User::factory(),
            'department_id' => null,
        ];
    }
}

<?php

namespace App\Services\Payroll;

use App\Models\Allowance;
use App\Models\Employee;
use Illuminate\Support\Collection;

class AllowanceAggregator
{
    /**
     * @return array{
     *     taxable_total: float,
     *     non_taxable_total: float,
     *     lines: array<int, array{label:string, type:string, amount:float, is_taxable:bool}>
     * }
     */
    public function aggregate(Employee $employee, \DateTimeInterface|string $periodDate, float $basic = 0.0): array
    {
        /** @var Collection<int, Allowance> $items */
        $items = Allowance::where('employee_id', $employee->id)
            ->effectiveOn($periodDate)
            ->get();

        // Cash emolument = basic + fixed cash allowances. Percentage-of-emolument
        // allowances (e.g. the fuel benefit-in-kind) are computed off this base,
        // so fixed allowances must be resolved first.
        $emolument = $basic;
        foreach ($items as $item) {
            if (($item->calc_method ?? Allowance::CALC_FIXED) === Allowance::CALC_FIXED) {
                $emolument += $item->resolveAmount($basic, $basic);
            }
        }

        $taxable    = 0.0;
        $nonTaxable = 0.0;
        $lines      = [];

        foreach ($items as $item) {
            $amount = $item->resolveAmount($basic, $emolument);
            if ($item->is_taxable) {
                $taxable += $amount;
            } else {
                $nonTaxable += $amount;
            }

            $lines[] = [
                'label'       => $item->label,
                'type'        => $item->type?->value ?? 'other',
                'amount'      => round($amount, 2),
                'is_taxable'  => (bool) $item->is_taxable,
                'calc_method' => $item->calc_method ?? Allowance::CALC_FIXED,
            ];
        }

        return [
            'taxable_total'     => round($taxable, 2),
            'non_taxable_total' => round($nonTaxable, 2),
            'lines'             => $lines,
        ];
    }
}

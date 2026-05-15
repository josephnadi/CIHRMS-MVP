<?php

namespace App\Services;

/**
 * Ghana payroll calculator.
 *
 * Implements PAYE monthly brackets (2024-2025) and the SSNIT 3-tier pension scheme
 * per the Ghana Revenue Authority and the National Pensions Regulatory Authority.
 *
 * Sources:
 *  - GRA Pay As You Earn (PAYE) — monthly tax bands for resident individuals.
 *  - SSNIT contribution rates (Tier 1 5.5% employee + 13% employer, Tier 2 5% employer).
 *  - NPRA 3-Tier scheme — Tier 3 voluntary, capped at 16.5% of basic salary.
 *  - Maximum Insurable Earnings cap: GHS 61,000/month effective 1 Jan 2025.
 */
class PayrollCalculator
{
    /**
     * Monthly PAYE bracket table for resident individuals.
     * Each entry: [upper_inclusive_threshold, marginal_rate].
     * Last entry is the top open-ended band (uses null upper bound).
     */
    public const PAYE_BRACKETS_MONTHLY = [
        ['upper' => 490.00,    'rate' => 0.00],
        ['upper' => 600.00,    'rate' => 0.05],
        ['upper' => 730.00,    'rate' => 0.10],
        ['upper' => 3_896.67,  'rate' => 0.175],
        ['upper' => 19_896.67, 'rate' => 0.25],
        ['upper' => 50_416.67, 'rate' => 0.30],
        ['upper' => null,      'rate' => 0.35], // open-ended top band
    ];

    public const SSNIT_TIER1_EMPLOYEE = 0.055;  // 5.5%
    public const SSNIT_TIER1_EMPLOYER = 0.13;   // 13%
    public const SSNIT_TIER2_EMPLOYER = 0.05;   // 5%
    public const SSNIT_MAX_INSURABLE  = 61_000;
    public const TIER3_MAX_COMBINED   = 0.165;  // 16.5% of basic salary, tax-deductible

    /**
     * Calculate a complete Ghana payslip from gross components.
     *
     * @param  float  $basic              Basic monthly salary (GHS)
     * @param  array  $cashAllowances     [['label' => 'Transport', 'amount' => 500.00], ...]
     * @param  array  $voluntaryDeductions[['label' => 'Loan',      'amount' => 200.00], ...]
     * @param  float  $tier3EmployeeShare Optional voluntary Tier 3 contribution by employee (GHS)
     */
    public function calculate(
        float $basic,
        array $cashAllowances = [],
        array $voluntaryDeductions = [],
        float $tier3EmployeeShare = 0.0,
    ): array {
        $allowanceTotal = array_sum(array_map(
            fn ($a) => (float) ($a['amount'] ?? 0),
            $cashAllowances,
        ));

        $voluntaryTotal = array_sum(array_map(
            fn ($d) => (float) ($d['amount'] ?? 0),
            $voluntaryDeductions,
        ));

        $gross = $basic + $allowanceTotal;

        // SSNIT base capped at Max Insurable Earnings
        $ssnitBase = min($basic, self::SSNIT_MAX_INSURABLE);

        $tier1Employee = round($ssnitBase * self::SSNIT_TIER1_EMPLOYEE, 2);
        $tier1Employer = round($ssnitBase * self::SSNIT_TIER1_EMPLOYER, 2);
        $tier2Employer = round($ssnitBase * self::SSNIT_TIER2_EMPLOYER, 2);

        // Tier 3 employee share is tax-deductible up to 16.5% of basic salary
        $tier3Cap         = round($basic * self::TIER3_MAX_COMBINED, 2);
        $tier3Deductible  = min(max($tier3EmployeeShare, 0), $tier3Cap);

        // Chargeable income = Gross − SSNIT employee − Tier 3 deductible portion
        $chargeable = max($gross - $tier1Employee - $tier3Deductible, 0);

        $paye        = $this->payeFromChargeable($chargeable);
        $payeBands   = $this->payeBreakdown($chargeable);

        $statutoryDeductionsTotal = $tier1Employee + $paye;
        $netPay = round($gross - $statutoryDeductionsTotal - $voluntaryTotal - $tier3EmployeeShare, 2);

        $employerCost = round($gross + $tier1Employer + $tier2Employer, 2);

        return [
            'inputs' => [
                'basic'               => round($basic, 2),
                'cash_allowances'     => $cashAllowances,
                'voluntary_deductions'=> $voluntaryDeductions,
                'tier3_employee'      => round($tier3EmployeeShare, 2),
            ],
            'earnings' => [
                'basic'           => round($basic, 2),
                'allowance_total' => round($allowanceTotal, 2),
                'gross'           => round($gross, 2),
            ],
            'statutory_deductions' => [
                'ssnit_tier1_employee' => $tier1Employee,
                'paye'                 => round($paye, 2),
                'total'                => round($statutoryDeductionsTotal, 2),
            ],
            'voluntary_deductions' => [
                'lines' => $voluntaryDeductions,
                'tier3_employee' => round($tier3EmployeeShare, 2),
                'total'  => round($voluntaryTotal + $tier3EmployeeShare, 2),
            ],
            'taxable' => [
                'gross'           => round($gross, 2),
                'ssnit_deduction' => $tier1Employee,
                'tier3_deduction' => round($tier3Deductible, 2),
                'chargeable'      => round($chargeable, 2),
                'paye_bands'      => $payeBands,
            ],
            'employer_cost' => [
                'gross'         => round($gross, 2),
                'ssnit_tier1'   => $tier1Employer,
                'ssnit_tier2'   => $tier2Employer,
                'total'         => $employerCost,
            ],
            'totals' => [
                'gross_earnings' => round($gross, 2),
                'total_deducted' => round($statutoryDeductionsTotal + $voluntaryTotal + $tier3EmployeeShare, 2),
                'net_pay'        => $netPay,
            ],
        ];
    }

    /**
     * Compute PAYE liability from monthly chargeable income using the bracket table.
     */
    public function payeFromChargeable(float $chargeable): float
    {
        if ($chargeable <= 0) {
            return 0.0;
        }

        $tax = 0.0;
        $previousUpper = 0.0;

        foreach (self::PAYE_BRACKETS_MONTHLY as $band) {
            $upper = $band['upper'];
            $rate  = $band['rate'];

            if ($upper === null) {
                $taxableInBand = $chargeable - $previousUpper;
                $tax += $taxableInBand * $rate;
                break;
            }

            $bandWidth = $upper - $previousUpper;

            if ($chargeable > $upper) {
                $tax += $bandWidth * $rate;
                $previousUpper = $upper;
                continue;
            }

            $taxableInBand = $chargeable - $previousUpper;
            $tax += $taxableInBand * $rate;
            break;
        }

        return round($tax, 2);
    }

    /**
     * Break the PAYE liability down by band so the UI can show the contribution
     * of each tax bracket.
     */
    public function payeBreakdown(float $chargeable): array
    {
        $rows = [];
        if ($chargeable <= 0) {
            return $rows;
        }

        $previousUpper = 0.0;

        foreach (self::PAYE_BRACKETS_MONTHLY as $band) {
            $upper = $band['upper'];
            $rate  = $band['rate'];

            if ($upper === null) {
                $taxableInBand = max($chargeable - $previousUpper, 0);
                if ($taxableInBand > 0) {
                    $rows[] = $this->bandRow($previousUpper, null, $rate, $taxableInBand);
                }
                break;
            }

            $bandWidth = $upper - $previousUpper;

            if ($chargeable > $upper) {
                $rows[] = $this->bandRow($previousUpper, $upper, $rate, $bandWidth);
                $previousUpper = $upper;
                continue;
            }

            $taxableInBand = $chargeable - $previousUpper;
            if ($taxableInBand > 0) {
                $rows[] = $this->bandRow($previousUpper, $upper, $rate, $taxableInBand);
            }
            break;
        }

        return $rows;
    }

    private function bandRow(float $lower, ?float $upper, float $rate, float $taxableInBand): array
    {
        return [
            'lower'  => round($lower, 2),
            'upper'  => $upper === null ? null : round($upper, 2),
            'rate'   => $rate,
            'amount' => round($taxableInBand, 2),
            'tax'    => round($taxableInBand * $rate, 2),
            'label'  => $upper === null
                ? sprintf('Above GHS %s @ %s%%', number_format($lower, 2), number_format($rate * 100, 1))
                : sprintf('GHS %s – %s @ %s%%', number_format($lower, 2), number_format($upper, 2), number_format($rate * 100, 1)),
        ];
    }
}

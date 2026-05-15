<?php

namespace Database\Seeders;

use App\Enums\AmortizationMethod;
use App\Enums\LoanProductType;
use App\Models\LoanProduct;
use Illuminate\Database\Seeder;

/**
 * Six demo loan products covering the typical public-sector lending menu.
 * Interest rates are illustrative — adjust to match the institution's
 * approved schedule.
 */
class DemoLoanProductSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'code' => 'SA-001', 'name' => 'Salary Advance',
                'type' => LoanProductType::SalaryAdvance,
                'min_amount' => 100, 'max_amount' => 5_000,
                'min_term_months' => 1, 'max_term_months' => 1,
                'annual_interest_rate' => 0,
                'amortization_method'  => AmortizationMethod::StraightLine,
                'requires_guarantor'   => false,
                'description' => 'Single-month interest-free advance, repaid from next payslip.',
            ],
            [
                'code' => 'EM-001', 'name' => 'Emergency Loan',
                'type' => LoanProductType::Emergency,
                'min_amount' => 500, 'max_amount' => 15_000,
                'min_term_months' => 3, 'max_term_months' => 12,
                'annual_interest_rate' => 0.08,
                'amortization_method'  => AmortizationMethod::ReducingBalance,
                'requires_guarantor'   => false,
                'description' => 'Fast-tracked small loan for emergencies (medical, bereavement, etc.).',
            ],
            [
                'code' => 'PL-001', 'name' => 'Personal Loan',
                'type' => LoanProductType::Personal,
                'min_amount' => 2_000, 'max_amount' => 80_000,
                'min_term_months' => 6, 'max_term_months' => 48,
                'annual_interest_rate' => 0.18,
                'amortization_method'  => AmortizationMethod::ReducingBalance,
                'requires_guarantor'   => true,
                'description' => 'General-purpose personal loan; requires two guarantors.',
            ],
            [
                'code' => 'ED-001', 'name' => 'Education Loan',
                'type' => LoanProductType::Education,
                'min_amount' => 5_000, 'max_amount' => 100_000,
                'min_term_months' => 12, 'max_term_months' => 60,
                'annual_interest_rate' => 0.12,
                'amortization_method'  => AmortizationMethod::ReducingBalance,
                'requires_guarantor'   => true,
                'description' => 'Subsidised rate for tuition, books, and tertiary fees.',
            ],
            [
                'code' => 'VL-001', 'name' => 'Vehicle Loan',
                'type' => LoanProductType::Vehicle,
                'min_amount' => 20_000, 'max_amount' => 250_000,
                'min_term_months' => 24, 'max_term_months' => 60,
                'annual_interest_rate' => 0.16,
                'amortization_method'  => AmortizationMethod::ReducingBalance,
                'requires_guarantor'   => true,
                'requires_collateral'  => true,
                'description' => 'Secured against the vehicle; comprehensive cover required.',
            ],
            [
                'code' => 'HL-001', 'name' => 'Housing Loan',
                'type' => LoanProductType::Housing,
                'min_amount' => 50_000, 'max_amount' => 1_500_000,
                'min_term_months' => 60, 'max_term_months' => 240,
                'annual_interest_rate' => 0.14,
                'amortization_method'  => AmortizationMethod::ReducingBalance,
                'requires_guarantor'   => true,
                'requires_collateral'  => true,
                'description' => 'Long-term mortgage-style loan against the financed property.',
            ],
        ];

        foreach ($rows as $row) {
            LoanProduct::updateOrCreate(
                ['code' => $row['code']],
                array_merge($row, [
                    'type'                => $row['type']->value,
                    'amortization_method' => $row['amortization_method']->value,
                    'is_active'           => true,
                    'effective_from'      => '2026-01-01',
                    'approvals_required'  => 2,
                ]),
            );
        }
    }
}

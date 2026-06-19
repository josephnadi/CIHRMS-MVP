<?php

namespace Database\Seeders;

use App\Models\StatutoryRate;
use App\Models\TaxBracket;
use Illuminate\Database\Seeder;

/**
 * Ghana statutory reference data for FY2026.
 *
 * Sources:
 *   - GRA monthly PAYE brackets (Income Tax Act 2015 (Act 896), as amended)
 *   - SSNIT: 13% employer + 5.5% employee = 18.5% total Tier-1 contribution
 *   - NHIA: 2.5% of basic, routed by SSNIT from the employer's 13%
 *   - Tier-2: 5% employer (mandatory since National Pensions Act 2008, Act 766)
 *   - Tier-3: up to 16.5% combined, tax-relieved
 *   - Maximum Insurable Earnings: GHS 61,000/month (effective 1 Jan 2025)
 *
 * Idempotent — re-running upserts rows by (code|lower_bound, effective_from).
 */
class GhanaStatutoryReferenceSeeder extends Seeder
{
    private const EFFECTIVE_FROM = '2026-01-01';

    private const PAYE_BRACKETS_MONTHLY = [
        ['lower' =>      0.00, 'upper' =>    490.00, 'rate' => 0.0000],
        ['lower' =>    490.00, 'upper' =>    600.00, 'rate' => 0.0500],
        ['lower' =>    600.00, 'upper' =>    730.00, 'rate' => 0.1000],
        ['lower' =>    730.00, 'upper' =>   3896.67, 'rate' => 0.1750],
        ['lower' =>   3896.67, 'upper' =>  19896.67, 'rate' => 0.2500],
        ['lower' =>  19896.67, 'upper' =>  50416.67, 'rate' => 0.3000],
        ['lower' =>  50416.67, 'upper' =>      null, 'rate' => 0.3500],
    ];

    private const STATUTORY_RATES = [
        [StatutoryRate::SSNIT_EMPLOYER,         'SSNIT Tier-1 Employer',          0.130000, true],
        [StatutoryRate::SSNIT_EMPLOYEE,         'SSNIT Tier-1 Employee',          0.055000, true],
        [StatutoryRate::NHIA_SPLIT,             'NHIA share of SSNIT employer',   0.025000, true],
        [StatutoryRate::TIER2_EMPLOYER,         'Tier-2 Employer (mandatory)',    0.050000, true],
        [StatutoryRate::TIER3_MAX_COMBINED,     'Tier-3 max combined (tax-relief)', 0.165000, true],
        [StatutoryRate::MAX_INSURABLE_EARNINGS, 'SSNIT Maximum Insurable Earnings', 61000.00, false],
    ];

    public function run(): void
    {
        // PAYE brackets — clear any rows with the same effective_from to avoid duplicates.
        TaxBracket::where('effective_from', self::EFFECTIVE_FROM)->where('cadence', 'monthly')->delete();

        $cumulative = 0.0;
        foreach (self::PAYE_BRACKETS_MONTHLY as $b) {
            TaxBracket::create([
                'jurisdiction'           => 'GH',
                'cadence'                => 'monthly',
                'lower_bound'            => $b['lower'],
                'upper_bound'            => $b['upper'],
                'rate'                   => $b['rate'],
                'cumulative_tax_at_lower'=> $cumulative,
                'effective_from'         => self::EFFECTIVE_FROM,
                'effective_to'           => null,
            ]);

            if ($b['upper'] !== null) {
                $cumulative += ($b['upper'] - $b['lower']) * $b['rate'];
            }
        }

        foreach (self::STATUTORY_RATES as [$code, $label, $rate, $isRate]) {
            StatutoryRate::updateOrCreate(
                ['code' => $code, 'effective_from' => self::EFFECTIVE_FROM],
                [
                    'label'        => $label,
                    'rate'         => $rate,
                    'is_rate'      => $isRate,
                    'currency'     => 'GHS',
                    'effective_to' => null,
                ],
            );
        }

        // Remittance deadline: SSNIT/GRA returns are due within 14 days of month-end.
        StatutoryRate::updateOrCreate(
            ['code' => StatutoryRate::REMITTANCE_DEADLINE_DAYS, 'effective_from' => '2020-01-01'],
            ['label' => 'Statutory remittance deadline (days after period end)', 'rate' => 14, 'is_rate' => false,
             'currency' => 'GHS', 'effective_to' => null, 'meta' => null],
        );
    }
}

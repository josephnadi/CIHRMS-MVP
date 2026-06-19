<?php

namespace App\Services\Offboarding;

use App\Enums\ExitType;
use App\Services\Payroll\PayeCalculator;

/**
 * Pure final-settlement calculator per Ghana Labour Act 2003 (Act 651).
 *
 * Rules implemented (sensible Ghana public-sector defaults — tweak via
 * the policy table if your scheme differs):
 *
 *  - Gratuity (Retirement / EndOfContract / Death):
 *        N months of basic per year of service, where N is the org policy
 *        (default 1.0 month — i.e. 1× basic per year served).
 *
 *  - Severance (Redundancy, Act 651 §31):
 *        Negotiated; statutory floor is 1× basic per year + 1 month notice
 *        in lieu where notice wasn't served. Default: 1.5× basic per year.
 *
 *  - Leave encashment:
 *        accrued_leave_days × (basic / working_days_per_month). 22 wd/month
 *        is the convention for monthly-paid public-service staff.
 *
 *  - Pro-rated 13th-month (if org policy gives one):
 *        (months_worked_in_year / 12) × basic
 *
 *  - Dismissal-with-cause and Abscondment: no gratuity, no severance.
 *        Still gets unpaid earned wages + leave encashment.
 *
 *  - PAYE on settlement:
 *        Computed on the gross settlement using the regular monthly bracket
 *        table. (Some jurisdictions tax lump-sum gratuity differently — that
 *        nuance is left as a future enhancement; see breakdown for visibility.)
 *
 * All amounts are snapshotted onto the FinalSettlement row so future Act
 * amendments don't silently rewrite history.
 */
class FinalSettlementCalculator
{
    /**
     * Default multipliers, expressed as months-of-basic per year of service.
     * Override per case via the `$overrides` argument from OffboardingService
     * (e.g. for collective-agreement schemes that pay 2× per year).
     */
    public const DEFAULT_GRATUITY_MONTHS_PER_YEAR    = 1.0;
    public const DEFAULT_SEVERANCE_MONTHS_PER_YEAR   = 1.5;
    public const DEFAULT_WORKING_DAYS_PER_MONTH      = 22.0;

    public function __construct(private readonly PayeCalculator $paye) {}

    /**
     * @param  array{
     *     gratuity_months_per_year?:float,
     *     severance_months_per_year?:float,
     *     working_days_per_month?:float,
     *     ex_gratia?:float,
     *     prorated_13th_month?:float,
     *     other_deductions?:float,
     *     garnishments?:float,
     *     pay_paye?:bool
     * } $overrides
     *
     * @return array{
     *     gratuity:float, severance:float, leave_encashment:float,
     *     prorated_13th_month:float, ex_gratia:float, gross_settlement:float,
     *     outstanding_loans:float, garnishments:float, other_deductions:float,
     *     total_deductions:float, paye_on_settlement:float, net_payable:float,
     *     working_days_per_month:float, breakdown:array
     * }
     */
    public function compute(
        ExitType $exitType,
        float $basicSalary,
        float $yearsOfService,
        float $accruedLeaveDays,
        float $outstandingLoans,
        \DateTimeInterface|string $effectiveDate,
        array $overrides = [],
    ): array {
        if ($basicSalary <= 0)    throw new \InvalidArgumentException('Basic salary must be > 0.');
        if ($yearsOfService < 0)  throw new \InvalidArgumentException('Years of service cannot be negative.');
        if ($accruedLeaveDays < 0) throw new \InvalidArgumentException('Accrued leave days cannot be negative.');

        $gratuityMonths   = (float) ($overrides['gratuity_months_per_year']  ?? self::DEFAULT_GRATUITY_MONTHS_PER_YEAR);
        $severanceMonths  = (float) ($overrides['severance_months_per_year'] ?? self::DEFAULT_SEVERANCE_MONTHS_PER_YEAR);
        $wdPerMonth       = (float) ($overrides['working_days_per_month']    ?? self::DEFAULT_WORKING_DAYS_PER_MONTH);
        $payPaye          = (bool)  ($overrides['pay_paye']                  ?? true);

        // ── Earnings ────────────────────────────────────────────────────────
        $gratuity = $exitType->qualifiesForGratuity()
            ? round($basicSalary * $gratuityMonths * $yearsOfService, 2)
            : 0.0;

        $severance = $exitType->qualifiesForSeverance()
            ? round($basicSalary * $severanceMonths * $yearsOfService, 2)
            : 0.0;

        $dailyRate       = $wdPerMonth > 0 ? $basicSalary / $wdPerMonth : 0.0;
        $leaveEncashment = round($accruedLeaveDays * $dailyRate, 2);

        $prorated13th = round((float) ($overrides['prorated_13th_month'] ?? 0), 2);
        $exGratia     = round((float) ($overrides['ex_gratia'] ?? 0), 2);

        $gross = round($gratuity + $severance + $leaveEncashment + $prorated13th + $exGratia, 2);

        // ── PAYE on the settlement (annualized bracket basis) ──────────────
        // A lump-sum terminal payment represents years of service, so taxing it
        // through the MONTHLY bracket table would shove almost all of it into the
        // top monthly band. Instead apply the ANNUAL table (monthly bands × 12),
        // which equals 12 × PAYE(gross / 12) — taxing the lump as a year's income.
        $paye = $payPaye ? round((float) $this->paye->calculate($gross / 12, $effectiveDate)['tax'] * 12, 2) : 0.0;

        // ── Deductions ──────────────────────────────────────────────────────
        $outstandingLoans = round(max(0.0, $outstandingLoans), 2);
        $garnishments     = round((float) ($overrides['garnishments'] ?? 0), 2);
        $otherDeductions  = round((float) ($overrides['other_deductions'] ?? 0), 2);

        $totalDeductions = round($outstandingLoans + $garnishments + $otherDeductions + $paye, 2);
        $netPayable      = round($gross - $totalDeductions, 2);

        return [
            'gratuity'             => $gratuity,
            'severance'            => $severance,
            'leave_encashment'     => $leaveEncashment,
            'prorated_13th_month'  => $prorated13th,
            'ex_gratia'            => $exGratia,
            'gross_settlement'     => $gross,

            'outstanding_loans'    => $outstandingLoans,
            'garnishments'         => $garnishments,
            'other_deductions'     => $otherDeductions,
            'total_deductions'     => $totalDeductions,

            'paye_on_settlement'   => $paye,
            'net_payable'          => $netPayable,
            'working_days_per_month' => $wdPerMonth,

            'breakdown' => [
                'inputs' => [
                    'exit_type'        => $exitType->value,
                    'basic_salary'     => round($basicSalary, 2),
                    'years_of_service' => round($yearsOfService, 2),
                    'accrued_leave'    => round($accruedLeaveDays, 2),
                    'daily_rate'       => round($dailyRate, 2),
                    'effective_date'   => $effectiveDate instanceof \DateTimeInterface
                        ? $effectiveDate->format('Y-m-d')
                        : $effectiveDate,
                ],
                'multipliers' => [
                    'gratuity_months_per_year'  => $gratuityMonths,
                    'severance_months_per_year' => $severanceMonths,
                    'pay_paye'                  => $payPaye,
                ],
                'narrative' => $this->narrative($exitType, $gratuity, $severance, $leaveEncashment),
            ],
        ];
    }

    private function narrative(ExitType $exitType, float $gratuity, float $severance, float $leave): string
    {
        $parts = [];
        if ($gratuity > 0)  $parts[] = sprintf('Gratuity GHS %s', number_format($gratuity, 2));
        if ($severance > 0) $parts[] = sprintf('Severance GHS %s (Act 651 §31)', number_format($severance, 2));
        if ($leave > 0)     $parts[] = sprintf('Leave encashment GHS %s', number_format($leave, 2));

        $reason = match (true) {
            ! $exitType->qualifiesForGratuity() && ! $exitType->qualifiesForSeverance()
                => "{$exitType->label()} — no gratuity or severance entitlement.",
            default => "{$exitType->label()} — settlement composed of: " . ($parts ? implode(', ', $parts) : 'leave encashment only.'),
        };

        return $reason;
    }
}

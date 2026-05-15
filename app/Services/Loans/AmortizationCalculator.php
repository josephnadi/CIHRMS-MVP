<?php

namespace App\Services\Loans;

use App\Enums\AmortizationMethod;

/**
 * Pure loan amortization calculator.
 *
 * Three methods supported (covers all common Ghana public-sector lending products):
 *
 *  - Straight-line: no interest, principal / n. Used for salary advances.
 *
 *  - Reducing balance (PMT formula): standard mortgage-style amortization.
 *        PMT = P × r / (1 − (1 + r)^−n)
 *      where r is the per-period rate (annual / 12) and n is the number of months.
 *      Each installment splits into a shrinking interest portion + growing
 *      principal portion as the outstanding balance decreases.
 *
 *  - Flat rate: total interest = P × annualRate × years, split evenly across
 *      all installments. Common at SACCO / welfare schemes despite being a
 *      higher *effective* rate than reducing balance.
 *
 * Invariants:
 *  - All amounts rounded to 2 dp.
 *  - Last installment absorbs rounding drift so total_repayable == sum(installments).
 *  - Same inputs → same outputs, always (pure function).
 */
class AmortizationCalculator
{
    /**
     * @return array{
     *     monthly_installment: float,
     *     total_interest: float,
     *     total_repayable: float,
     *     schedule: array<int, array{
     *         installment_no:int,
     *         scheduled_amount:float,
     *         principal_portion:float,
     *         interest_portion:float,
     *         balance_after:float
     *     }>
     * }
     */
    public function calculate(
        float $principal,
        int $termMonths,
        float $annualRate,
        AmortizationMethod $method,
    ): array {
        if ($principal <= 0) {
            throw new \InvalidArgumentException('Principal must be > 0.');
        }
        if ($termMonths < 1) {
            throw new \InvalidArgumentException('Term must be at least 1 month.');
        }
        if ($annualRate < 0) {
            throw new \InvalidArgumentException('Interest rate cannot be negative.');
        }

        return match ($method) {
            AmortizationMethod::StraightLine    => $this->straightLine($principal, $termMonths),
            AmortizationMethod::ReducingBalance => $this->reducingBalance($principal, $termMonths, $annualRate),
            AmortizationMethod::FlatRate        => $this->flatRate($principal, $termMonths, $annualRate),
        };
    }

    private function straightLine(float $principal, int $n): array
    {
        $perInstallment = round($principal / $n, 2);

        $schedule = [];
        $running = $principal;
        for ($i = 1; $i <= $n; $i++) {
            $payment = $i === $n ? round($running, 2) : $perInstallment;
            $running = round($running - $payment, 2);
            $schedule[] = [
                'installment_no'    => $i,
                'scheduled_amount'  => $payment,
                'principal_portion' => $payment,
                'interest_portion'  => 0.0,
                'balance_after'     => max($running, 0.0),
            ];
        }

        return [
            'monthly_installment' => $perInstallment,
            'total_interest'      => 0.0,
            'total_repayable'     => round($principal, 2),
            'schedule'            => $schedule,
        ];
    }

    private function reducingBalance(float $principal, int $n, float $annualRate): array
    {
        $r = $annualRate / 12.0;

        if ($r === 0.0) {
            return $this->straightLine($principal, $n); // identical math when r=0
        }

        // PMT = P × r / (1 − (1+r)^−n)
        $pmt = ($principal * $r) / (1 - pow(1 + $r, -$n));
        $pmtRounded = round($pmt, 2);

        $schedule = [];
        $balance = $principal;
        $totalInterest = 0.0;
        $totalPaid = 0.0;

        for ($i = 1; $i <= $n; $i++) {
            $interest = round($balance * $r, 2);

            if ($i === $n) {
                // Final installment absorbs rounding drift
                $principalPortion = round($balance, 2);
                $payment = round($principalPortion + $interest, 2);
                $balanceAfter = 0.0;
            } else {
                $principalPortion = round($pmtRounded - $interest, 2);
                $payment = $pmtRounded;
                $balance = round($balance - $principalPortion, 2);
                $balanceAfter = max($balance, 0.0);
            }

            $totalInterest += $interest;
            $totalPaid += $payment;

            $schedule[] = [
                'installment_no'    => $i,
                'scheduled_amount'  => $payment,
                'principal_portion' => $principalPortion,
                'interest_portion'  => $interest,
                'balance_after'     => $balanceAfter,
            ];
        }

        return [
            'monthly_installment' => $pmtRounded,
            'total_interest'      => round($totalInterest, 2),
            'total_repayable'     => round($totalPaid, 2),
            'schedule'            => $schedule,
        ];
    }

    private function flatRate(float $principal, int $n, float $annualRate): array
    {
        $years         = $n / 12.0;
        $totalInterest = round($principal * $annualRate * $years, 2);
        $totalRepay    = round($principal + $totalInterest, 2);
        $perInstallment = round($totalRepay / $n, 2);
        $principalPart  = round($principal / $n, 2);
        $interestPart   = round($totalInterest / $n, 2);

        $schedule = [];
        $balance = $principal;
        $paidPrincipal = 0.0;
        $paidTotal = 0.0;

        for ($i = 1; $i <= $n; $i++) {
            $isLast = $i === $n;
            $principalPortion = $isLast ? round($principal - $paidPrincipal, 2) : $principalPart;
            $interestPortion  = $isLast ? round($totalInterest - ($interestPart * ($n - 1)), 2) : $interestPart;
            $payment          = $isLast ? round($totalRepay - $paidTotal, 2) : $perInstallment;

            $balance = round($balance - $principalPortion, 2);
            $paidPrincipal = round($paidPrincipal + $principalPortion, 2);
            $paidTotal = round($paidTotal + $payment, 2);

            $schedule[] = [
                'installment_no'    => $i,
                'scheduled_amount'  => $payment,
                'principal_portion' => $principalPortion,
                'interest_portion'  => $interestPortion,
                'balance_after'     => max($balance, 0.0),
            ];
        }

        return [
            'monthly_installment' => $perInstallment,
            'total_interest'      => $totalInterest,
            'total_repayable'     => $totalRepay,
            'schedule'            => $schedule,
        ];
    }
}

<?php

namespace App\Enums;

enum LoanProductType: string
{
    case SalaryAdvance = 'salary_advance';   // Short-term, interest-free, single-month repayment
    case Personal      = 'personal';         // Generic short/medium-term
    case Emergency     = 'emergency';        // Fast-tracked, lower limit
    case Education     = 'education';        // Long-term, lower rate
    case Housing       = 'housing';          // Long-term, secured
    case Vehicle       = 'vehicle';          // Medium-term, secured
    case Other         = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SalaryAdvance => 'Salary Advance',
            self::Personal      => 'Personal Loan',
            self::Emergency     => 'Emergency Loan',
            self::Education     => 'Education Loan',
            self::Housing       => 'Housing Loan',
            self::Vehicle       => 'Vehicle Loan',
            self::Other         => 'Other',
        };
    }

    public function requiresGuarantor(): bool
    {
        return in_array($this, [self::Housing, self::Vehicle, self::Personal], true);
    }
}

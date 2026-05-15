<?php

namespace App\Enums;

enum DeductionType: string
{
    case LoanRepayment   = 'loan_repayment';
    case SalaryAdvance   = 'salary_advance';
    case Garnishment     = 'garnishment';
    case UnionDues       = 'union_dues';
    case Sacco           = 'sacco';
    case Welfare         = 'welfare';
    case Tier3Voluntary  = 'tier3_voluntary';
    case Other           = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LoanRepayment  => 'Loan Repayment',
            self::SalaryAdvance  => 'Salary Advance',
            self::Garnishment    => 'Court-Ordered Garnishment',
            self::UnionDues      => 'Union Dues',
            self::Sacco          => 'SACCO Contribution',
            self::Welfare        => 'Staff Welfare',
            self::Tier3Voluntary => 'Tier-3 Voluntary Pension',
            self::Other          => 'Other',
        };
    }

    /** Garnishments execute first (court order); voluntary deductions last. */
    public function priority(): int
    {
        return match ($this) {
            self::Garnishment    => 10,
            self::LoanRepayment  => 20,
            self::SalaryAdvance  => 30,
            self::Tier3Voluntary => 40,
            self::Sacco          => 50,
            self::UnionDues      => 60,
            self::Welfare        => 70,
            self::Other          => 80,
        };
    }
}

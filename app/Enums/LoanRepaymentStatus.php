<?php

namespace App\Enums;

enum LoanRepaymentStatus: string
{
    case Scheduled = 'scheduled';   // Future installment in the schedule
    case Paid      = 'paid';        // Successfully posted from a payroll run
    case Missed    = 'missed';      // Period passed without payment
    case Deferred  = 'deferred';    // Officially deferred (carry-forward)
    case Waived    = 'waived';      // HR/Finance forgave this installment

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

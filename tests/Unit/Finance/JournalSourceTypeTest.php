<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;

it('exposes the new universal-posting source types with labels', function () {
    expect(JournalSourceType::Payroll->value)->toBe('payroll')
        ->and(JournalSourceType::Disbursement->value)->toBe('disbursement')
        ->and(JournalSourceType::LoanDisbursement->value)->toBe('loan_disbursement')
        ->and(JournalSourceType::LoanRepayment->value)->toBe('loan_repayment')
        ->and(JournalSourceType::MemberFee->value)->toBe('member_fee')
        ->and(JournalSourceType::Payroll->label())->toBe('Payroll')
        ->and(JournalSourceType::LoanDisbursement->label())->toBe('Loan Disbursement');
});

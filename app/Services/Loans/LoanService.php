<?php

namespace App\Services\Loans;

use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Events\LoanApproved;
use App\Events\LoanDisbursed;
use App\Events\LoanFullyRepaid;
use App\Models\Employee;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Services\Finance\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the loan lifecycle: apply → approve → disburse → repay → paid_off.
 *
 * Snapshots product terms (interest rate, amortization method) onto the
 * LoanAccount at application time so subsequent product edits don't
 * silently re-price an active loan.
 *
 * The repayment schedule is generated atomically at disbursement; each row
 * is later claimed by exactly one payroll run.
 */
class LoanService
{
    public function __construct(
        private readonly AmortizationCalculator $calc,
        private readonly SequenceService $sequences,
    ) {}

    public function apply(
        Employee $employee,
        LoanProduct $product,
        float $principal,
        int $termMonths,
        ?string $purpose,
        User $applicant,
    ): LoanAccount {
        $this->validateApplication($product, $principal, $termMonths);

        $bundle = $this->calc->calculate(
            principal:   $principal,
            termMonths:  $termMonths,
            annualRate:  (float) $product->annual_interest_rate,
            method:      $product->amortization_method,
        );

        return DB::transaction(function () use ($employee, $product, $principal, $termMonths, $purpose, $applicant, $bundle) {
            $loan = LoanAccount::create([
                'reference'                  => $this->generateReference(),
                'employee_id'                => $employee->id,
                'product_id'                 => $product->id,
                'status'                     => LoanStatus::PendingApproval->value,
                'principal'                  => round($principal, 2),
                'term_months'                => $termMonths,
                'booked_interest_rate'       => $product->annual_interest_rate,
                'booked_amortization_method' => $product->amortization_method->value,
                'monthly_installment'        => $bundle['monthly_installment'],
                'total_interest'             => $bundle['total_interest'],
                'total_repayable'            => $bundle['total_repayable'],
                'outstanding_balance'        => $bundle['total_repayable'],
                'purpose'                    => $purpose,
                'applied_by'                 => $applicant->id,
                'applied_at'                 => now(),
            ]);

            return $loan;
        });
    }

    public function approve(LoanAccount $loan, User $approver): LoanAccount
    {
        if ($loan->status !== LoanStatus::PendingApproval) {
            throw new \DomainException('Only pending-approval loans can be approved.');
        }
        if ($loan->applied_by === $approver->id) {
            throw new \DomainException('Dual-control violation: approver must differ from applicant.');
        }

        $loan->update([
            'status'      => LoanStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        event(new LoanApproved($loan));

        return $loan->fresh();
    }

    public function reject(LoanAccount $loan, User $approver, string $reason): LoanAccount
    {
        if ($loan->status !== LoanStatus::PendingApproval) {
            throw new \DomainException('Only pending-approval loans can be rejected.');
        }

        $loan->update([
            'status'           => LoanStatus::Rejected,
            'rejection_reason' => $reason,
            'approved_by'      => $approver->id,
            'approved_at'      => now(),
        ]);

        return $loan->fresh();
    }

    /**
     * Disburse the loan, generate the full repayment schedule, and transition
     * the loan to `disbursed`. Subsequent payroll runs claim installments.
     *
     * `firstRepaymentPeriod` defaults to the first day of the month after disbursement.
     */
    public function disburse(LoanAccount $loan, User $disburser, ?CarbonImmutable $firstRepaymentPeriod = null): LoanAccount
    {
        if ($loan->status !== LoanStatus::Approved) {
            throw new \DomainException('Only approved loans can be disbursed.');
        }

        $firstPeriod = $firstRepaymentPeriod ?? CarbonImmutable::now()->startOfMonth()->addMonth();
        $expectedEnd = $firstPeriod->addMonths($loan->term_months - 1);

        // Re-derive the schedule from the snapshotted booking terms.
        $bundle = $this->calc->calculate(
            principal:   (float) $loan->principal,
            termMonths:  (int)   $loan->term_months,
            annualRate:  (float) $loan->booked_interest_rate,
            method:      $loan->booked_amortization_method,
        );

        return DB::transaction(function () use ($loan, $disburser, $firstPeriod, $expectedEnd, $bundle) {
            // Insert one LoanRepayment per row of the schedule
            $cursor = $firstPeriod;
            foreach ($bundle['schedule'] as $row) {
                LoanRepayment::create([
                    'loan_account_id'   => $loan->id,
                    'installment_no'    => $row['installment_no'],
                    'due_period'        => $cursor->toDateString(),
                    'scheduled_amount'  => $row['scheduled_amount'],
                    'principal_portion' => $row['principal_portion'],
                    'interest_portion'  => $row['interest_portion'],
                    'balance_after'     => $row['balance_after'],
                    'status'            => LoanRepaymentStatus::Scheduled->value,
                ]);
                $cursor = $cursor->addMonth();
            }

            $loan->update([
                'status'                 => LoanStatus::Disbursed,
                'disbursed_by'           => $disburser->id,
                'disbursed_at'           => now(),
                'disbursed_amount'       => $loan->principal,
                'outstanding_balance'    => $loan->total_repayable,
                'first_repayment_period' => $firstPeriod->toDateString(),
                'expected_end_period'    => $expectedEnd->toDateString(),
            ]);

            event(new LoanDisbursed($loan));

            return $loan->fresh();
        });
    }

    /**
     * Mark a single repayment as posted from a payroll run. Decrements the
     * loan's outstanding balance and flips status to `paid_off` if final.
     */
    public function postRepayment(LoanRepayment $repayment, int $payrollRunId, ?int $payrollLineId = null): LoanAccount
    {
        if ($repayment->status === LoanRepaymentStatus::Paid) {
            $repayment->loadMissing('loan');
            return $repayment->loan->fresh();
        }

        return DB::transaction(function () use ($repayment, $payrollRunId, $payrollLineId) {
            $repayment->update([
                'status'          => LoanRepaymentStatus::Paid->value,
                'paid_amount'     => $repayment->scheduled_amount,
                'payroll_run_id'  => $payrollRunId,
                'payroll_line_id' => $payrollLineId,
                'posted_at'       => now(),
            ]);

            /** @var LoanAccount $loan */
            $loan = $repayment->loan()->lockForUpdate()->first();

            $newBalance = max(0.0, round((float) $loan->outstanding_balance - (float) $repayment->scheduled_amount, 2));
            $newPaid    = (int) $loan->installments_paid + 1;

            $update = [
                'outstanding_balance' => $newBalance,
                'installments_paid'   => $newPaid,
                'status'              => LoanStatus::Repaying->value,
            ];

            if ($newBalance <= 0.0 || $newPaid >= (int) $loan->term_months) {
                $update['status']          = LoanStatus::PaidOff->value;
                $update['actual_end_date'] = now()->toDateString();
            }

            $loan->update($update);

            if ($update['status'] === LoanStatus::PaidOff->value) {
                event(new LoanFullyRepaid($loan));
            }

            return $loan->fresh();
        });
    }

    private function validateApplication(LoanProduct $product, float $principal, int $termMonths): void
    {
        if (! $product->is_active) {
            throw new \DomainException("Loan product '{$product->code}' is not active.");
        }
        if ($principal < (float) $product->min_amount || $principal > (float) $product->max_amount) {
            throw new \DomainException("Principal {$principal} outside product limits [{$product->min_amount}, {$product->max_amount}].");
        }
        if ($termMonths < (int) $product->min_term_months || $termMonths > (int) $product->max_term_months) {
            throw new \DomainException("Term {$termMonths} outside product limits [{$product->min_term_months}, {$product->max_term_months}].");
        }
    }

    private function generateReference(): string
    {
        // Year-scoped sequential. SequenceService row-locks the counter so
        // concurrent applications cannot collide on the unique reference.
        $year = now()->year;
        $n    = $this->sequences->next("loan:{$year}");
        return sprintf('LOAN-%04d-%05d', $year, $n);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Offboarding;

use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Models\FinalSettlement;
use App\Models\JournalEntry;
use App\Models\LoanAccount;
use App\Models\LoanRepayment;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Illuminate\Support\Collection;

/**
 * Posts the General-Ledger accrual for an approved final settlement and clears
 * the leaver's loans against it. The gross settlement is the expense (5130);
 * PAYE, the cleared loan (principal→1300, interest→4600), other deductions, and
 * the net payable are the credits. The loan is cleared only as far as the gross
 * can absorb it (whole installments, oldest first) — any uncovered installments
 * stay scheduled and owed.
 */
class SettlementPostingService
{
    private const TOLERANCE = 0.005;

    public function __construct(private readonly PostingService $posting)
    {
    }

    public function postAccrual(FinalSettlement $settlement, User $actor): ?JournalEntry
    {
        $gross = round((float) $settlement->gross_settlement, 2);
        if ($gross <= 0.0) {
            return null; // nothing to recognise; no loan can be netted
        }

        $paye       = round((float) $settlement->paye_on_settlement, 2);
        $deductions = round((float) $settlement->garnishments + (float) $settlement->other_deductions, 2);
        $capacity   = round(max(0.0, $gross - $paye - $deductions), 2);

        $cleared = $this->selectClearableInstallments($settlement, $capacity);

        $principalCleared = round((float) $cleared->sum(fn (LoanRepayment $i) => (float) $i->principal_portion), 2);
        $interestCleared  = round((float) $cleared->sum(fn (LoanRepayment $i) => (float) $i->interest_portion), 2);
        $loanCleared      = round($principalCleared + $interestCleared, 2);
        $netPay           = round($gross - $paye - $deductions - $loanCleared, 2);

        $this->applyClearing($cleared, $settlement);

        $lines = [PostingLine::debit(slug: 'settlement.benefits_expense', amount: $gross, narration: 'Final settlement gross')];
        if ($paye > 0.0) {
            $lines[] = PostingLine::credit(slug: 'settlement.paye_payable', amount: $paye, narration: 'PAYE on settlement');
        }
        if ($principalCleared > 0.0) {
            $lines[] = PostingLine::credit(slug: 'loan.principal_receivable', amount: $principalCleared, narration: 'Loan principal cleared from settlement');
        }
        if ($interestCleared > 0.0) {
            $lines[] = PostingLine::credit(slug: 'loan.interest_income', amount: $interestCleared, narration: 'Loan interest collected via settlement');
        }
        if ($deductions > 0.0) {
            $lines[] = PostingLine::credit(slug: 'settlement.deductions_payable', amount: $deductions, narration: 'Garnishments & other deductions');
        }
        if ($netPay > 0.0) {
            $lines[] = PostingLine::credit(slug: 'settlement.net_pay_payable', amount: $netPay, narration: 'Net settlement payable');
        }

        return $this->posting->post(new PostingDocument(
            sourceType: JournalSourceType::FinalSettlement,
            sourceId: $settlement->id,
            purpose: 'accrual',
            date: now()->toDateString(),
            narration: "Final settlement accrual (case {$settlement->offboarding_case_id})",
            lines: $lines,
        ), $actor);
    }

    /** Whole scheduled installments, oldest first, whose running total fits the capacity. */
    private function selectClearableInstallments(FinalSettlement $settlement, float $capacity): Collection
    {
        $case = OffboardingCase::find($settlement->offboarding_case_id);
        $employeeId = $case?->employee_id;
        if ($employeeId === null) {
            return collect();
        }

        $loanIds = LoanAccount::where('employee_id', $employeeId)
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value])
            ->lockForUpdate()
            ->pluck('id');

        if ($loanIds->isEmpty()) {
            return collect();
        }

        $installments = LoanRepayment::whereIn('loan_account_id', $loanIds)
            ->where('status', LoanRepaymentStatus::Scheduled->value)
            ->orderBy('due_period')
            ->orderBy('installment_no')
            ->lockForUpdate()
            ->get();

        $cleared = collect();
        $running = 0.0;
        foreach ($installments as $inst) {
            $next = round($running + (float) $inst->scheduled_amount, 2);
            if ($next <= $capacity + self::TOLERANCE) {
                $cleared->push($inst);
                $running = $next;
            } else {
                break;
            }
        }

        return $cleared;
    }

    /** Waive the cleared installments and update each affected loan. */
    private function applyClearing(Collection $cleared, FinalSettlement $settlement): void
    {
        if ($cleared->isEmpty()) {
            return;
        }

        foreach ($cleared->groupBy('loan_account_id') as $loanId => $insts) {
            LoanRepayment::whereIn('id', $insts->pluck('id'))->update([
                'status'    => LoanRepaymentStatus::Waived->value,
                'notes'     => 'Cleared from final settlement ' . $settlement->id,
                'posted_at' => now(),
            ]);

            $loan = LoanAccount::find($loanId);
            if (! $loan) {
                continue;
            }

            $remaining = LoanRepayment::where('loan_account_id', $loanId)
                ->where('status', LoanRepaymentStatus::Scheduled->value)
                ->count();

            if ($remaining === 0) {
                $loan->update([
                    'status'              => LoanStatus::PaidOff->value,
                    'outstanding_balance' => 0,
                    'actual_end_date'     => now()->toDateString(),
                ]);
            } else {
                $clearedAmt = round((float) $insts->sum(fn (LoanRepayment $i) => (float) $i->scheduled_amount), 2);
                $loan->update([
                    'outstanding_balance' => round(max(0.0, (float) $loan->outstanding_balance - $clearedAmt), 2),
                ]);
            }
        }
    }
}

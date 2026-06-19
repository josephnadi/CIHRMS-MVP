<?php

namespace App\Services\Offboarding;

use App\Enums\ClearanceArea;
use App\Enums\ClearanceItemStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ExitType;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\LoanRepaymentStatus;
use App\Enums\LoanStatus;
use App\Enums\OffboardingStatus;
use App\Enums\SettlementStatus;
use App\Events\OffboardingCompleted;
use App\Events\OffboardingInitiated;
use App\Events\SettlementApproved;
use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\JournalEntry;
use App\Models\LeaveBalance;
use App\Models\LoanAccount;
use App\Models\LoanRepayment;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Services\Finance\PostingService;
use App\Services\Finance\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle orchestrator for off-boarding cases.
 *
 *   initiate
 *     → seedDefaultClearance (it_assets / finance / hr_records / stores / pension)
 *     → clearItem / waiveItem (per-area sign-off)
 *     → calculateSettlement (snapshots numbers — idempotent)
 *     → approveSettlement (locks the figures, posts loan write-offs)
 *     → complete (flips Employee status to Terminated, closes loans, archives identity)
 */
class OffboardingService
{
    /**
     * Default clearance checklist seeded when a case is initiated.
     * Each entry: [area, label, required?]
     */
    public const DEFAULT_CLEARANCE_TEMPLATE = [
        [ClearanceArea::ItAssets,     'Return laptop, mobile device & SIM',           true],
        [ClearanceArea::ItAssets,     'Disable accounts and revoke access',           true],
        [ClearanceArea::ItAssets,     'Return ID & access badge',                     true],
        [ClearanceArea::Finance,      'Reconcile outstanding imprest / advances',      true],
        [ClearanceArea::Finance,      'Settle outstanding loans (or schedule netting)',true],
        [ClearanceArea::HrRecords,    'Return staff file documents',                   true],
        [ClearanceArea::HrRecords,    'Sign exit interview',                           true],
        [ClearanceArea::HrRecords,    'Reaffirm NDA / confidentiality obligations',    true],
        [ClearanceArea::Stores,       'Return uniforms / tools / vehicles',            false],
        [ClearanceArea::Security,     'Gate pass & parking access revoked',           true],
        [ClearanceArea::DeptHandover, 'Departmental handover note signed by supervisor', true],
        [ClearanceArea::Pension,      'SSNIT discharge form filed; Tier-2 trustee notified', true],
    ];

    public function __construct(
        private readonly FinalSettlementCalculator $calculator,
        private readonly SequenceService $sequences,
        private readonly SettlementPostingService $settlementPosting,
        private readonly PostingService $posting,
    ) {}

    public function initiate(
        Employee $employee,
        ExitType $exitType,
        \DateTimeInterface|string $noticeReceivedOn,
        \DateTimeInterface|string $lastWorkingDay,
        User $initiator,
        ?string $reason = null,
    ): OffboardingCase {
        if ($employee->status === EmployeeStatus::Terminated) {
            throw new \DomainException("Employee {$employee->employee_no} is already terminated.");
        }
        if ($this->openCaseFor($employee)) {
            throw new \DomainException("Employee {$employee->employee_no} already has an open off-boarding case.");
        }

        return DB::transaction(function () use ($employee, $exitType, $noticeReceivedOn, $lastWorkingDay, $initiator, $reason) {
            $case = OffboardingCase::create([
                'reference'                  => $this->nextReference(),
                'employee_id'                => $employee->id,
                'initiated_by'               => $initiator->id,
                'exit_type'                  => $exitType->value,
                'status'                     => OffboardingStatus::InProgress->value,
                'notice_received_on'         => $this->toDateString($noticeReceivedOn),
                'last_working_day'           => $this->toDateString($lastWorkingDay),
                'effective_termination_date' => $this->toDateString($lastWorkingDay),
                'reason'                     => $reason,
            ]);

            $this->seedDefaultClearance($case);

            event(new OffboardingInitiated($case));

            return $case->fresh('clearanceItems');
        });
    }

    public function addClearanceItem(OffboardingCase $case, ClearanceArea $area, string $label, bool $required = true, ?int $responsibleDeptId = null, ?int $responsibleUserId = null): ClearanceItem
    {
        return ClearanceItem::create([
            'offboarding_case_id'        => $case->id,
            'area'                       => $area->value,
            'label'                      => $label,
            'status'                     => ClearanceItemStatus::Pending->value,
            'responsible_department_id'  => $responsibleDeptId,
            'responsible_user_id'        => $responsibleUserId,
            'is_required'                => $required,
        ]);
    }

    public function clearItem(ClearanceItem $item, User $clearer, ?string $notes = null, array $evidencePaths = []): ClearanceItem
    {
        if ($item->status !== ClearanceItemStatus::Pending) {
            throw new \DomainException("Item '{$item->label}' is not pending (current: {$item->status->value}).");
        }

        $item->update([
            'status'         => ClearanceItemStatus::Cleared->value,
            'cleared_by'     => $clearer->id,
            'cleared_at'     => now(),
            'notes'          => $notes,
            'evidence_paths' => $evidencePaths ?: null,
        ]);

        // Strict mode disables lazy loading; fetch the case explicitly.
        $case = OffboardingCase::find($item->offboarding_case_id);
        if ($case) $this->maybeAdvanceCaseStatus($case);

        return $item->fresh();
    }

    public function waiveItem(ClearanceItem $item, User $waiver, string $reason): ClearanceItem
    {
        if ($item->status !== ClearanceItemStatus::Pending) {
            throw new \DomainException("Item '{$item->label}' is not pending.");
        }

        $item->update([
            'status'     => ClearanceItemStatus::Waived->value,
            'cleared_by' => $waiver->id,
            'cleared_at' => now(),
            'notes'      => $reason,
        ]);

        $case = OffboardingCase::find($item->offboarding_case_id);
        if ($case) $this->maybeAdvanceCaseStatus($case);

        return $item->fresh();
    }

    /**
     * Snapshot a final settlement from current employee state + Act 651 rules.
     * Idempotent — re-running on an unapproved case replaces the prior snapshot.
     */
    public function calculateSettlement(OffboardingCase $case, User $actor, array $overrides = []): FinalSettlement
    {
        if ($case->status->isTerminal()) {
            throw new \DomainException('Cannot calculate settlement on a closed case.');
        }

        // Eager-load relations explicitly — strict mode forbids lazy loads.
        $case->loadMissing(['settlement', 'employee.currentGrade']);

        if ($case->settlement && $case->settlement->status === SettlementStatus::Approved) {
            throw new \DomainException('Settlement already approved — cannot recalculate. Cancel first.');
        }

        $employee = $case->employee;
        if (! $employee) {
            throw new \DomainException('Case has no linked employee.');
        }

        $basicSalary    = $this->resolveBasicSalary($employee);
        $yearsService   = $this->yearsOfService($employee, $case->effective_termination_date);
        $accruedLeave   = $this->accruedLeaveDays($employee);
        $outstandingLoan = $this->outstandingLoanBalance($employee);

        $r = $this->calculator->compute(
            exitType:          $case->exit_type,
            basicSalary:       $basicSalary,
            yearsOfService:    $yearsService,
            accruedLeaveDays:  $accruedLeave,
            outstandingLoans:  $outstandingLoan,
            effectiveDate:     $case->effective_termination_date,
            overrides:         $overrides,
        );

        return DB::transaction(function () use ($case, $actor, $basicSalary, $yearsService, $accruedLeave, $r) {
            // Soft-delete any prior unapproved snapshot
            if ($case->settlement && $case->settlement->status !== SettlementStatus::Approved) {
                $case->settlement->delete();
            }

            $settlement = FinalSettlement::create([
                'offboarding_case_id'    => $case->id,
                'status'                 => SettlementStatus::Calculated->value,
                'basic_salary'           => $basicSalary,
                'years_of_service'       => $yearsService,
                'accrued_leave_days'     => $accruedLeave,
                'working_days_per_month' => $r['working_days_per_month'],
                'gratuity'               => $r['gratuity'],
                'severance'              => $r['severance'],
                'leave_encashment'       => $r['leave_encashment'],
                'prorated_13th_month'    => $r['prorated_13th_month'],
                'ex_gratia'              => $r['ex_gratia'],
                'gross_settlement'       => $r['gross_settlement'],
                'outstanding_loans'      => $r['outstanding_loans'],
                'garnishments'           => $r['garnishments'],
                'other_deductions'       => $r['other_deductions'],
                'total_deductions'       => $r['total_deductions'],
                'paye_on_settlement'     => $r['paye_on_settlement'],
                'net_payable'            => $r['net_payable'],
                'calculated_by'          => $actor->id,
                'calculated_at'          => now(),
                'breakdown'              => $r['breakdown'],
            ]);

            $case->update(['status' => OffboardingStatus::AwaitingSettlement->value]);

            return $settlement;
        });
    }

    /**
     * Approve the calculated settlement and book the loan write-off (if any).
     * Dual control: calculator ≠ approver.
     */
    public function approveSettlement(FinalSettlement $settlement, User $approver): FinalSettlement
    {
        if ($settlement->status !== SettlementStatus::Calculated) {
            throw new \DomainException('Only calculated settlements can be approved.');
        }
        if ($settlement->calculated_by === $approver->id) {
            throw new \DomainException('Dual-control violation: approver must differ from calculator.');
        }

        return DB::transaction(function () use ($settlement, $approver) {
            $settlement->update([
                'status'      => SettlementStatus::Approved->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            // Recognise the settlement in the GL and clear the leaver's loans against it.
            $this->settlementPosting->postAccrual($settlement, $approver);

            event(new SettlementApproved($settlement));

            return $settlement->fresh();
        });
    }

    /**
     * Pay an approved settlement: post the cash JE (DR net-pay payable / CR
     * payroll bank) and flip the settlement to Paid. Approved → Paid only.
     */
    public function paySettlement(FinalSettlement $settlement, User $payer): FinalSettlement
    {
        if ($settlement->status !== SettlementStatus::Approved) {
            throw new \DomainException('Only an approved settlement can be paid.');
        }

        return DB::transaction(function () use ($settlement, $payer) {
            $this->settlementPosting->postPayment($settlement, $payer);

            $settlement->update([
                'status'  => SettlementStatus::Paid->value,
                'paid_at' => now(),
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * Reverse an approved or paid settlement: un-post its GL entries (payment
     * then accrual), restore the loans it cleared, and mark it Cancelled.
     */
    public function reverseSettlement(FinalSettlement $settlement, User $by, string $reason): FinalSettlement
    {
        if (! in_array($settlement->status, [SettlementStatus::Approved, SettlementStatus::Paid], true)) {
            throw new \DomainException('Only an approved or paid settlement can be reversed.');
        }

        return DB::transaction(function () use ($settlement, $by, $reason) {
            // 1) Reverse the GL entries that exist (payment first, then accrual).
            foreach (['payment', 'accrual'] as $purpose) {
                $posted = JournalEntry::where('source_type', JournalSourceType::FinalSettlement->value)
                    ->where('source_id', $settlement->id)
                    ->where('source_purpose', $purpose)
                    ->where('status', JournalEntryStatus::Posted->value)
                    ->exists();

                if ($posted) {
                    $this->posting->reverseFor(
                        JournalSourceType::FinalSettlement,
                        $settlement->id,
                        $purpose,
                        $by,
                        "Settlement reversal: {$reason}",
                    );
                }
            }

            // 2) Restore the loans this settlement cleared.
            $this->restoreClearedLoans($settlement);

            // 3) Mark the settlement cancelled with the reason.
            $settlement->update([
                'status' => SettlementStatus::Cancelled->value,
                'notes'  => trim(($settlement->notes ? $settlement->notes . "\n" : '') . "[REVERSED] {$reason}"),
            ]);

            return $settlement->fresh();
        });
    }

    /** Un-waive the installments this settlement cleared and rebuild each affected loan. */
    private function restoreClearedLoans(FinalSettlement $settlement): void
    {
        $marker = LoanRepayment::settlementClearingNote($settlement->id);

        $restored = LoanRepayment::where('notes', $marker)
            ->where('status', LoanRepaymentStatus::Waived->value)
            ->lockForUpdate()
            ->get();

        if ($restored->isEmpty()) {
            return;
        }

        foreach ($restored->groupBy('loan_account_id') as $loanId => $insts) {
            LoanRepayment::whereIn('id', $insts->pluck('id'))->update([
                'status'    => LoanRepaymentStatus::Scheduled->value,
                'notes'     => null,
                'posted_at' => null,
            ]);

            $loan = LoanAccount::find($loanId);
            if (! $loan) {
                continue;
            }

            $outstanding = (float) LoanRepayment::where('loan_account_id', $loanId)
                ->where('status', LoanRepaymentStatus::Scheduled->value)
                ->sum('scheduled_amount');

            $loan->update([
                'status'              => LoanStatus::Repaying->value,
                'outstanding_balance' => round($outstanding, 2),
                'actual_end_date'     => null,
            ]);
        }
    }

    /**
     * Complete the case — flips the employee row to Terminated, voids future
     * loan repayments, archives the verification, and locks the case.
     */
    public function complete(OffboardingCase $case, User $actor): OffboardingCase
    {
        if ($case->status === OffboardingStatus::Completed) return $case;

        // Eager-load relations the method needs — strict mode disallows lazy loads.
        $case->loadMissing(['settlement', 'employee']);

        if (! $case->isClearanceComplete()) {
            throw new \DomainException('Cannot complete: required clearance items still pending.');
        }
        if (! $case->settlement || $case->settlement->status !== SettlementStatus::Approved) {
            throw new \DomainException('Cannot complete: settlement must be approved first.');
        }

        return DB::transaction(function () use ($case, $actor) {
            $case->update([
                'status'        => OffboardingStatus::Completed->value,
                'completed_by'  => $actor->id,
                'completed_at'  => now(),
            ]);

            $case->employee?->update(['status' => EmployeeStatus::Terminated->value]);

            event(new OffboardingCompleted($case));

            return $case->fresh(['employee', 'settlement']);
        });
    }

    public function cancel(OffboardingCase $case, User $actor, string $reason): OffboardingCase
    {
        if ($case->status === OffboardingStatus::Completed) {
            throw new \DomainException('Cannot cancel a completed case.');
        }

        $case->update([
            'status' => OffboardingStatus::Cancelled->value,
            'reason' => $case->reason ? $case->reason . "\n\n[CANCELLED] " . $reason : "[CANCELLED] {$reason}",
            'completed_by' => $actor->id,
            'completed_at' => now(),
        ]);

        return $case->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function seedDefaultClearance(OffboardingCase $case): void
    {
        foreach (self::DEFAULT_CLEARANCE_TEMPLATE as [$area, $label, $required]) {
            ClearanceItem::create([
                'offboarding_case_id' => $case->id,
                'area'                => $area->value,
                'label'               => $label,
                'status'              => ClearanceItemStatus::Pending->value,
                'is_required'         => $required,
            ]);
        }
    }

    private function maybeAdvanceCaseStatus(OffboardingCase $case): void
    {
        if ($case->status !== OffboardingStatus::InProgress) return;

        if ($case->isClearanceComplete()) {
            $case->update(['status' => OffboardingStatus::AwaitingSettlement->value]);
        }
    }

    private function openCaseFor(Employee $employee): ?OffboardingCase
    {
        return OffboardingCase::where('employee_id', $employee->id)->open()->first();
    }

    private function resolveBasicSalary(Employee $employee): float
    {
        if ($employee->currentGrade && $employee->current_step) {
            $base = $employee->currentGrade->baseSalaryFor((int) $employee->current_step, now());
            if ($base !== null) return $base;
        }
        return (float) ($employee->salary ?? 0);
    }

    private function yearsOfService(Employee $employee, \DateTimeInterface|string $terminationDate): float
    {
        if (! $employee->hire_date) return 0.0;
        $hire = CarbonImmutable::parse($employee->hire_date);
        $end  = $terminationDate instanceof \DateTimeInterface
            ? CarbonImmutable::instance($terminationDate)
            : CarbonImmutable::parse($terminationDate);

        return round($hire->floatDiffInYears($end), 2);
    }

    private function accruedLeaveDays(Employee $employee): float
    {
        $year = now()->year;
        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('type', 'annual')
            ->get();

        $total = 0.0;
        foreach ($balances as $b) {
            $total += max(0.0, (float) $b->total_days - (float) $b->used_days);
        }
        return round($total, 2);
    }

    private function outstandingLoanBalance(Employee $employee): float
    {
        return (float) LoanAccount::where('employee_id', $employee->id)
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value])
            ->sum('outstanding_balance');
    }

    private function nextReference(): string
    {
        $year = now()->year;
        $n    = $this->sequences->next("offboarding:{$year}");
        return sprintf('OFF-%04d-%05d', $year, $n);
    }

    private function toDateString(\DateTimeInterface|string $d): string
    {
        return $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d;
    }
}

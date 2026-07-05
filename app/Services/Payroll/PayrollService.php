<?php

namespace App\Services\Payroll;

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\PayrollRunStatus;
use App\Events\PayrollRunApproved;
use App\Events\PayrollRunCalculated;
use App\Events\PayrollRunReversed;
use App\Events\PayrollRunStarted;
use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Loans\LoanService;
use App\Models\LoanRepayment;
use App\Enums\LoanRepaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the period-locked payroll-run lifecycle:
 *   draft → calculating → calculated → approved → paid
 *                                                  ↓
 *                                              reversed
 *
 * Each step is atomic, audited, and reproducible. The `breakdown` JSON on
 * every PayrollLine stores the calculator output for the run's effective date,
 * so a re-calculation of a closed period would produce the same totals even
 * if statutory rates change tomorrow.
 */
class PayrollService
{
    public function __construct(
        private readonly PayeCalculator $paye,
        private readonly SsnitCalculator $ssnit,
        private readonly Tier2Calculator $tier2,
        private readonly Tier3Calculator $tier3,
        private readonly AllowanceAggregator $allowances,
        private readonly DeductionAggregator $deductions,
        private readonly AttendanceService $attendance,
        private readonly LoanService $loans,
        private readonly PostingService $posting,
    ) {
    }

    public function createDraft(int $year, int $month, ?int $departmentId, User $creator, ?string $reason = null): PayrollRun
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $end   = $start->endOfMonth();

        $reference = sprintf('PR-%04d-%02d-%s', $year, $month, $departmentId ? "D{$departmentId}" : 'ORG');

        return DB::transaction(function () use ($year, $month, $start, $end, $departmentId, $creator, $reason, $reference) {
            $run = PayrollRun::create([
                'reference'     => $reference,
                'period_year'   => $year,
                'period_month'  => $month,
                'period_start'  => $start->toDateString(),
                'period_end'    => $end->toDateString(),
                'status'        => PayrollRunStatus::Draft,
                'department_id' => $departmentId,
                'created_by'    => $creator->id,
                'reason'        => $reason,
            ]);

            event(new PayrollRunStarted($run));

            return $run;
        });
    }

    public function calculate(PayrollRun $run): PayrollRun
    {
        if ($run->status === PayrollRunStatus::Approved || $run->status === PayrollRunStatus::Paid) {
            throw new \DomainException('Cannot recalculate a closed payroll run.');
        }

        return DB::transaction(function () use ($run) {
            $run->update(['status' => PayrollRunStatus::Calculating]);
            $run->lines()->delete();

            $periodEnd = CarbonImmutable::parse($run->period_end);

            $employees = Employee::query()
                ->with(['currentGrade.steps', 'currentPosition', 'tier2Trustee'])
                ->when($run->department_id, fn ($q) => $q->where('department_id', $run->department_id))
                ->active()
                ->get();

            $totals = $this->resetTotals();
            $skipped = 0;

            $periodStart = CarbonImmutable::parse($run->period_start);

            foreach ($employees as $employee) {
                // Gate 1: identity verification (Phase 1)
                if (! $employee->hasUsableIdentity()) {
                    $this->skipLine($run, $employee, 'Identity unverified — Ghana Card validation required.');
                    $skipped++;
                    continue;
                }

                // Gate 2: attendance — zero-attendance employees are the ghost-worker signal.
                // We pay only those who have at least one recorded working day in the period.
                $attendance = $this->attendance->aggregatePeriod($employee, $periodStart, $periodEnd);
                if ($attendance['days_worked'] === 0 && $attendance['days_on_leave'] === 0) {
                    $this->skipLine(
                        $run,
                        $employee,
                        sprintf(
                            'No attendance recorded in %d working days — potential ghost worker.',
                            $attendance['working_days'],
                        ),
                    );
                    $skipped++;
                    continue;
                }

                $line = $this->calculateLine($run, $employee, $periodEnd);
                $this->accumulate($totals, $line);
            }

            $run->update([
                'status'                      => PayrollRunStatus::Calculated,
                'locked_at'                   => now(),
                'lines_count'                 => $employees->count() - $skipped,
                'skipped_count'               => $skipped,
                'gross_total'                 => $totals['gross'],
                'net_total'                   => $totals['net'],
                'paye_total'                  => $totals['paye'],
                'ssnit_tier1_employee_total'  => $totals['ssnit_employee'],
                'ssnit_tier1_employer_total'  => $totals['ssnit_employer'],
                'nhia_total'                  => $totals['nhia'],
                'tier2_employer_total'        => $totals['tier2'],
                'tier3_total'                 => $totals['tier3'],
                'voluntary_deductions_total'  => $totals['voluntary'],
            ]);

            event(new PayrollRunCalculated($run));

            return $run->fresh(['lines', 'department']);
        });
    }

    private function calculateLine(PayrollRun $run, Employee $employee, CarbonImmutable $periodDate): PayrollLine
    {
        $basic = $this->resolveBasicSalary($employee, $periodDate);

        $allowanceBundle = $this->allowances->aggregate($employee, $periodDate, $basic);
        $allowanceTotal  = round($allowanceBundle['taxable_total'] + $allowanceBundle['non_taxable_total'], 2);
        $gross           = round($basic + $allowanceTotal, 2);

        // Overtime supplement: sum overtime_hours recorded in the pay period.
        // overtime_hours on AttendanceSummary already incorporates premium multipliers
        // from OvertimeCalculator, so a simple hourly-rate multiplication is correct here.
        $overtimeHours = (float) AttendanceSummary::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('summary_date', [$run->period_start, $run->period_end])
            ->sum('overtime_hours');

        $overtimePay = 0.0;
        if ($overtimeHours > 0) {
            $hourlyRate  = $basic / 173.33; // 173.33 = avg monthly working hours (52w × 40h / 12)
            $overtimePay = round($overtimeHours * $hourlyRate, 2);
            $gross       = round($gross + $overtimePay, 2);
        }

        $ssnit = $this->ssnit->calculate($basic, $periodDate);
        $tier2 = $this->tier2->calculate($basic, $periodDate);
        $tier3 = $this->tier3->calculate($basic, (float) ($employee->tier3_rate ?? 0), $periodDate);

        // Chargeable income = (gross taxable income) - SSNIT employee - relieved Tier-3.
        // Non-taxable allowances are excluded from chargeable income. Tier-3 relief is
        // capped at (16.5−5)% of basic; any elected excess stays in chargeable (taxed).
        $taxableGross = round($basic + $allowanceBundle['taxable_total'], 2);
        $chargeable   = max(round($taxableGross - $ssnit['employee'] - $tier3['relieved'], 2), 0);

        $payeBundle = $this->paye->calculate($chargeable, $periodDate);
        $paye       = (float) $payeBundle['tax'];

        $netAfterStatutory = round($gross - $ssnit['employee'] - $tier3['employee'] - $paye, 2);
        $deductionBundle   = $this->deductions->aggregate($employee, $gross, $netAfterStatutory, $periodDate);

        // Loan repayments — claim scheduled installments for this employee for the
        // run's period, but DO NOT mark them paid yet (that happens on run approval).
        $loanBundle = $this->collectLoanRepayments($employee, $run);

        $totalVoluntary = round($deductionBundle['total'] + $loanBundle['total'], 2);
        $net            = round($netAfterStatutory - $totalVoluntary, 2);

        return PayrollLine::create([
            'payroll_run_id'        => $run->id,
            'employee_id'           => $employee->id,
            'position_id'           => $employee->current_position_id,
            'grade_id'              => $employee->current_grade_id,
            'step'                  => $employee->current_step,

            'basic'                 => round($basic, 2),
            'allowance_total'       => $allowanceTotal,
            'gross'                 => $gross,
            'overtime_hours'        => $overtimeHours,
            'overtime_pay'          => $overtimePay,
            'ssnit_base'            => $ssnit['base'],
            'ssnit_tier1_employee'  => $ssnit['employee'],
            'ssnit_tier1_employer'  => $ssnit['employer'],
            'nhia_split'            => $ssnit['nhia_split'],
            'tier2_employer'        => $tier2['employer'],
            'tier3_employee'        => $tier3['employee'],
            'paye'                  => round($paye, 2),
            'voluntary_deductions'  => $totalVoluntary,
            'net'                   => $net,

            'breakdown'             => [
                'allowances'    => $allowanceBundle,
                'overtime'      => ['hours' => $overtimeHours, 'pay' => $overtimePay],
                'ssnit'         => $ssnit,
                'tier2'         => $tier2,
                'paye_bands'    => $payeBundle['bands'],
                'deductions'    => $deductionBundle,
                'loans'         => $loanBundle,
                'effective_on'  => $periodDate->toDateString(),
            ],
            'status'                => 'calculated',
        ]);
    }

    /**
     * Find scheduled loan repayments for the employee that fall in the run's
     * pay period, and return them as a deduction bundle. Repayment rows are
     * NOT mutated here — they're posted on PayrollRun::approve().
     *
     * @return array{
     *     total:float,
     *     lines:array<int, array{loan_reference:string, installment_no:int, amount:float, repayment_id:int}>
     * }
     */
    private function collectLoanRepayments(Employee $employee, PayrollRun $run): array
    {
        $period = sprintf('%04d-%02d-01', $run->period_year, $run->period_month);

        $repayments = LoanRepayment::query()
            ->with('loan:id,reference,status')
            ->where('status', LoanRepaymentStatus::Scheduled->value)
            ->whereDate('due_period', $period)
            ->whereHas('loan', fn ($q) => $q
                ->where('employee_id', $employee->id)
                ->activeForRepayment())
            ->get();

        $lines = $repayments->map(fn (LoanRepayment $r) => [
            'loan_reference' => $r->loan?->reference ?? '—',
            'installment_no' => (int) $r->installment_no,
            'amount'         => (float) $r->scheduled_amount,
            'repayment_id'   => (int) $r->id,
        ])->all();

        return [
            'total' => round((float) $repayments->sum('scheduled_amount'), 2),
            'lines' => $lines,
        ];
    }

    private function resolveBasicSalary(Employee $employee, CarbonImmutable $periodDate): float
    {
        // Preferred path: grade + step lookup. Stable across history.
        if ($employee->currentGrade && $employee->current_step) {
            $base = $employee->currentGrade->baseSalaryFor((int) $employee->current_step, $periodDate);
            if ($base !== null) return $base;
        }

        // Fallback to legacy `employees.salary` column for back-compat during migration.
        return (float) ($employee->salary ?? 0);
    }

    public function approve(PayrollRun $run, User $approver): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Calculated) {
            throw new \DomainException('Only calculated runs can be approved.');
        }
        if ($run->created_by === $approver->id) {
            throw new \DomainException('Dual-control violation: approver must differ from creator.');
        }

        return DB::transaction(function () use ($run, $approver) {
            $run->update([
                'status'      => PayrollRunStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            // Finalize loan repayments captured during calculateLine().
            // Each line's breakdown.loans.lines[].repayment_id is now posted,
            // which marks the LoanRepayment paid and decrements the loan balance.
            $run->lines()->calculated()->get()->each(function ($line) use ($run) {
                $loanLines = data_get($line->breakdown, 'loans.lines', []);
                foreach ($loanLines as $loanLine) {
                    $repayment = LoanRepayment::find($loanLine['repayment_id'] ?? null);
                    if ($repayment && $repayment->status === LoanRepaymentStatus::Scheduled) {
                        $this->loans->postRepayment($repayment, $run->id, $line->id);
                    }
                }
            });

            $this->posting->post($this->buildAccrualDocument($run), $approver);

            event(new PayrollRunApproved($run));

            return $run->fresh();
        });
    }

    private function reverseAccrualIfPosted(PayrollRun $run, User $by, string $reason): void
    {
        $hasAccrual = JournalEntry::query()
            ->where('source_type', JournalSourceType::Payroll->value)
            ->where('source_id', $run->id)
            ->where('source_purpose', 'accrual')
            ->where('status', JournalEntryStatus::Posted->value)
            ->exists();

        if ($hasAccrual) {
            $this->posting->reverseFor(JournalSourceType::Payroll, $run->id, 'accrual', $by, "Payroll reversed: {$reason}");
        }
    }

    private function buildAccrualDocument(PayrollRun $run): PostingDocument
    {
        $basicPlusOvertime = round(
            (float) $run->lines()->calculated()->sum('basic')
            + (float) $run->lines()->calculated()->sum('overtime_pay'),
            2,
        );
        $allowance = round((float) $run->lines()->calculated()->sum('allowance_total'), 2);

        $loanPrincipal = round((float) LoanRepayment::where('payroll_run_id', $run->id)->sum('principal_portion'), 2);
        $loanInterest  = round((float) LoanRepayment::where('payroll_run_id', $run->id)->sum('interest_portion'), 2);

        $employerContrib  = round((float) $run->ssnit_tier1_employer_total + (float) $run->tier2_employer_total, 2);
        $ssnitPayable     = round((float) $run->ssnit_tier1_employee_total + (float) $run->ssnit_tier1_employer_total, 2);
        $voluntaryNonLoan = round((float) $run->voluntary_deductions_total - $loanPrincipal - $loanInterest, 2);

        $debit  = fn (string $slug, float $amt, string $note) => $amt > 0 ? PostingLine::debit(slug: $slug, amount: $amt, narration: $note) : null;
        $credit = fn (string $slug, float $amt, string $note) => $amt > 0 ? PostingLine::credit(slug: $slug, amount: $amt, narration: $note) : null;

        $candidates = [
            $debit('payroll.salary_expense',              $basicPlusOvertime,                  'Basic + overtime'),
            $debit('payroll.allowance_expense',           $allowance,                          'Allowances'),
            $debit('payroll.employer_contrib_expense',    $employerContrib,                    'Employer SSNIT + Tier-2'),
            $credit('payroll.net_pay_payable',            round((float) $run->net_total, 2),   'Net pay'),
            $credit('payroll.paye_payable',               round((float) $run->paye_total, 2),  'PAYE'),
            $credit('payroll.ssnit_payable',              $ssnitPayable,                       'SSNIT employee + employer'),
            $credit('payroll.tier2_payable',              round((float) $run->tier2_employer_total, 2), 'Tier-2'),
            $credit('payroll.tier3_payable',              round((float) $run->tier3_total, 2), 'Tier-3 voluntary'),
            $credit('loan.principal_receivable',          $loanPrincipal,                      'Loan principal recovered'),
            $credit('loan.interest_income',               $loanInterest,                       'Loan interest recovered'),
            $credit('payroll.voluntary_deductions_payable', $voluntaryNonLoan,                 'Voluntary deductions'),
        ];

        $lines = array_values(array_filter($candidates));

        return new PostingDocument(
            sourceType: JournalSourceType::Payroll,
            sourceId: $run->id,
            purpose: 'accrual',
            date: $run->period_end->toDateString(),
            narration: "Payroll accrual: {$run->reference}",
            lines: $lines,
        );
    }

    public function reverse(PayrollRun $run, User $reverser, string $reason): PayrollRun
    {
        if ($run->status->isTerminal() && $run->status !== PayrollRunStatus::Paid) {
            throw new \DomainException('Cannot reverse a non-paid, non-approved run.');
        }

        return DB::transaction(function () use ($run, $reverser, $reason) {
            $run->update([
                'status'      => PayrollRunStatus::Reversed,
                'reversed_by' => $reverser->id,
                'reversed_at' => now(),
                'reason'      => $reason,
            ]);

            $run->lines()->update(['status' => 'reversed']);

            $this->reverseAccrualIfPosted($run, $reverser, $reason);

            event(new PayrollRunReversed($run, $reason));

            return $run->fresh();
        });
    }

    public function markPaid(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Approved) {
            throw new \DomainException('Only approved runs can be marked paid.');
        }

        $run->update([
            'status'  => PayrollRunStatus::Paid,
            'paid_at' => now(),
        ]);

        $fresh = $run->fresh();
        \App\Events\PayrollRunPaid::dispatch($fresh);
        return $fresh;
    }

    private function skipLine(PayrollRun $run, Employee $employee, string $reason): void
    {
        PayrollLine::create([
            'payroll_run_id' => $run->id,
            'employee_id'    => $employee->id,
            'basic'          => 0, 'allowance_total' => 0, 'gross' => 0,
            'ssnit_base'     => 0, 'ssnit_tier1_employee' => 0, 'ssnit_tier1_employer' => 0,
            'nhia_split'     => 0, 'tier2_employer' => 0, 'tier3_employee' => 0,
            'paye'           => 0, 'voluntary_deductions' => 0, 'net' => 0,
            'status'         => 'skipped',
            'skip_reason'    => $reason,
        ]);
    }

    private function resetTotals(): array
    {
        return [
            'gross' => 0.0, 'net' => 0.0, 'paye' => 0.0,
            'ssnit_employee' => 0.0, 'ssnit_employer' => 0.0,
            'nhia' => 0.0, 'tier2' => 0.0, 'tier3' => 0.0,
            'voluntary' => 0.0,
        ];
    }

    private function accumulate(array &$totals, PayrollLine $line): void
    {
        $totals['gross']          += (float) $line->gross;
        $totals['net']            += (float) $line->net;
        $totals['paye']           += (float) $line->paye;
        $totals['ssnit_employee'] += (float) $line->ssnit_tier1_employee;
        $totals['ssnit_employer'] += (float) $line->ssnit_tier1_employer;
        $totals['nhia']           += (float) $line->nhia_split;
        $totals['tier2']          += (float) $line->tier2_employer;
        $totals['tier3']          += (float) $line->tier3_employee;
        $totals['voluntary']      += (float) $line->voluntary_deductions;
    }
}

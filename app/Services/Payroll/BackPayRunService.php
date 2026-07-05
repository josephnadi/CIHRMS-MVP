<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\JournalSourceType;
use App\Models\BackPayLine;
use App\Models\BackPayRun;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\SequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of a back-pay (arrears) run: draft → approved → paid.
 *
 *  - create()   snapshots the arrears computed by BackPayService into a run + one
 *               line per employee (accumulated deltas + a per-month breakdown).
 *  - approve()  enforces dual control and posts the catch-up accrual to the GL,
 *               mirroring a payroll accrual but with arrears amounts only.
 *  - markPaid() closes the run once the net + back-PAYE have been disbursed.
 */
class BackPayRunService
{
    public function __construct(
        private readonly BackPayService $backPay,
        private readonly PostingService $posting,
        private readonly SequenceService $sequences,
    ) {
    }

    /**
     * Snapshot the arrears owed for a revision into a draft run.
     *
     * @param array<int>|null $employeeIds  limit to these employees (null = all)
     */
    public function create(SalaryRevision $revision, User $creator, ?array $employeeIds = null): BackPayRun
    {
        // One live arrears run per revision — never double-pay the same catch-up.
        $existing = BackPayRun::query()
            ->where('salary_revision_id', $revision->id)
            ->where('status', '!=', BackPayRun::STATUS_REVERSED)
            ->first();
        if ($existing) {
            throw new DomainException("A back-pay run ({$existing->reference}) already exists for revision {$revision->reference}.");
        }

        $arrears = $this->backPay->computeForRevision($revision, $employeeIds);
        if ($arrears === []) {
            throw new DomainException('No arrears to pay — no approved payroll months on or after this revision.');
        }

        return DB::transaction(function () use ($revision, $creator, $arrears) {
            $year = now()->format('Y');
            $run  = BackPayRun::create([
                'reference'          => sprintf('BPR-%s-%06d', $year, $this->sequences->next("backpay:{$year}")),
                'salary_revision_id' => $revision->id,
                'effective_from'     => $revision->effective_from,
                'status'             => BackPayRun::STATUS_DRAFT,
                'created_by'         => $creator->id,
                'employees_count'    => count($arrears),
            ]);

            $totals = [
                'gross' => 0.0, 'arrears_net' => 0.0, 'back_paye' => 0.0,
                'ssnit_employee' => 0.0, 'ssnit_employer' => 0.0,
                'tier2_employer' => 0.0, 'tier3_employee' => 0.0,
            ];

            foreach ($arrears as $row) {
                BackPayLine::create([
                    'back_pay_run_id' => $run->id,
                    'employee_id'     => $row['employee_id'],
                    'gross'           => $row['gross'],
                    'arrears_net'     => $row['arrears_net'],
                    'back_paye'       => $row['back_paye'],
                    'ssnit_employee'  => $row['ssnit_employee'],
                    'ssnit_employer'  => $row['ssnit_employer'],
                    'tier2_employer'  => $row['tier2_employer'],
                    'tier3_employee'  => $row['tier3_employee'],
                    'breakdown'       => $row['months'],
                ]);

                foreach ($totals as $key => $_) {
                    $totals[$key] = round($totals[$key] + (float) $row[$key], 2);
                }
            }

            $run->update([
                'gross_total'          => $totals['gross'],
                'arrears_net_total'    => $totals['arrears_net'],
                'back_paye_total'      => $totals['back_paye'],
                'ssnit_employee_total' => $totals['ssnit_employee'],
                'ssnit_employer_total' => $totals['ssnit_employer'],
                'tier2_employer_total' => $totals['tier2_employer'],
                'tier3_employee_total' => $totals['tier3_employee'],
            ]);

            return $run->fresh('lines');
        });
    }

    public function approve(BackPayRun $run, User $approver): BackPayRun
    {
        if ($run->status !== BackPayRun::STATUS_DRAFT) {
            throw new DomainException('Only a draft back-pay run can be approved.');
        }
        if ($run->created_by === $approver->id) {
            throw new DomainException('Dual-control violation: approver must differ from creator.');
        }

        return DB::transaction(function () use ($run, $approver) {
            $run->update([
                'status'      => BackPayRun::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $this->posting->post($this->buildAccrualDocument($run), $approver);

            return $run->fresh('lines');
        });
    }

    public function markPaid(BackPayRun $run): BackPayRun
    {
        if ($run->status !== BackPayRun::STATUS_APPROVED) {
            throw new DomainException('Only an approved back-pay run can be marked paid.');
        }

        $run->update(['status' => BackPayRun::STATUS_PAID, 'paid_at' => now()]);

        return $run->fresh('lines');
    }

    /**
     * Catch-up accrual — recognised in the current open period (not the past
     * months themselves, which stay closed). Balances exactly like a payroll
     * accrual: the increase in staff cost + employer contributions equals the
     * increase in net pay, PAYE and statutory payables.
     */
    private function buildAccrualDocument(BackPayRun $run): PostingDocument
    {
        $employerContrib = round((float) $run->ssnit_employer_total + (float) $run->tier2_employer_total, 2);
        $ssnitPayable    = round((float) $run->ssnit_employee_total + (float) $run->ssnit_employer_total, 2);

        $debit  = fn (string $slug, float $amt, string $note) => $amt > 0 ? PostingLine::debit(slug: $slug, amount: $amt, narration: $note) : null;
        $credit = fn (string $slug, float $amt, string $note) => $amt > 0 ? PostingLine::credit(slug: $slug, amount: $amt, narration: $note) : null;

        $candidates = [
            $debit('payroll.salary_expense',           round((float) $run->gross_total, 2),          'Arrears: basic + allowances'),
            $debit('payroll.employer_contrib_expense', $employerContrib,                             'Arrears: employer SSNIT + Tier-2'),
            $credit('payroll.net_pay_payable',         round((float) $run->arrears_net_total, 2),    'Arrears net pay'),
            $credit('payroll.paye_payable',            round((float) $run->back_paye_total, 2),      'Back-PAYE'),
            $credit('payroll.ssnit_payable',           $ssnitPayable,                                'Arrears SSNIT employee + employer'),
            $credit('payroll.tier2_payable',           round((float) $run->tier2_employer_total, 2), 'Arrears Tier-2'),
            $credit('payroll.tier3_payable',           round((float) $run->tier3_employee_total, 2), 'Arrears Tier-3 voluntary'),
        ];

        $lines = array_values(array_filter($candidates));

        return new PostingDocument(
            sourceType: JournalSourceType::BackPay,
            sourceId: $run->id,
            purpose: 'accrual',
            date: now()->toDateString(),
            narration: "Back-pay accrual: {$run->reference}",
            lines: $lines,
        );
    }
}

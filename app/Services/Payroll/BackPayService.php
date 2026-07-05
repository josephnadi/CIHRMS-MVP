<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\PayrollRunStatus;
use App\Models\Grade;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\SalaryRevision;
use Carbon\CarbonImmutable;

/**
 * Computes retroactive back-pay (arrears) for a salary revision — CIHRM's
 * "Back Pay" / "Back PAYE" columns. For each already-approved/paid payroll month
 * on or after the revision's effective date, it recomputes the payslip at the
 * OLD basic (what was paid) and the NEW revised basic, and takes the deltas.
 * The two computations share PayrollService::computeCore, so the tax matches the
 * live payroll exactly.
 */
class BackPayService
{
    public function __construct(private readonly PayrollService $payroll) {}

    /**
     * @param array<int>|null $employeeIds  limit to these employees (null = all)
     * @return array<int, array{
     *   employee_id:int, employee_name:?string, employee_no:?string,
     *   arrears_net:float, back_paye:float, gross:float,
     *   ssnit_employee:float, ssnit_employer:float, tier2_employer:float, tier3_employee:float,
     *   months:array<int, array{
     *     period:string, old_basic:float, new_basic:float, arrears:float, back_paye:float}>
     * }>
     */
    public function computeForRevision(SalaryRevision $revision, ?array $employeeIds = null): array
    {
        $effective = CarbonImmutable::parse($revision->effective_from);

        $runs = PayrollRun::query()
            ->whereIn('status', [PayrollRunStatus::Approved->value, PayrollRunStatus::Paid->value])
            ->where(function ($q) use ($effective) {
                $q->where('period_year', '>', $effective->year)
                  ->orWhere(fn ($qq) => $qq->where('period_year', $effective->year)
                                           ->where('period_month', '>=', $effective->month));
            })
            ->orderBy('period_year')->orderBy('period_month')
            ->get();

        $acc = [];  // employee_id => accumulator

        foreach ($runs as $run) {
            $periodDate = CarbonImmutable::parse($run->period_end);
            $label      = $periodDate->format('M Y');

            $lines = PayrollLine::query()
                ->where('payroll_run_id', $run->id)
                ->where('status', 'calculated')
                ->when($employeeIds, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->with('employee.user:id,name')
                ->get();

            foreach ($lines as $line) {
                $employee = $line->employee;
                if (! $employee) {
                    continue;
                }

                $oldBasic = round((float) $line->basic, 2);
                $newBasic = $this->revisedBasic((int) $line->grade_id, (int) $line->step, $periodDate);
                if ($newBasic === null || $newBasic <= $oldBasic + 0.005) {
                    continue; // no revision effect for this month (already at new rate, or none)
                }

                $old = $this->payroll->computeCore($employee, $oldBasic, $periodDate);
                $new = $this->payroll->computeCore($employee, $newBasic, $periodDate);

                $arrears  = round($new['net'] - $old['net'], 2);
                $backPaye = round($new['paye'] - $old['paye'], 2);

                // Statutory contribution deltas — the increased basic also raises SSNIT
                // (employee + employer), Tier-2 (employer) and any relieved Tier-3. These
                // are what make the arrears accrual balance like a normal payroll accrual.
                $dGross         = round($new['gross'] - $old['gross'], 2);
                $dSsnitEmployee = round($new['ssnit']['employee'] - $old['ssnit']['employee'], 2);
                $dSsnitEmployer = round($new['ssnit']['employer'] - $old['ssnit']['employer'], 2);
                $dTier2         = round($new['tier2']['employer'] - $old['tier2']['employer'], 2);
                $dTier3         = round($new['tier3']['employee'] - $old['tier3']['employee'], 2);

                $id = (int) $line->employee_id;
                $acc[$id] ??= [
                    'employee_id'    => $id,
                    'employee_name'  => $employee->user?->name ?? $employee->full_name ?? null,
                    'employee_no'    => $employee->employee_no,
                    'arrears_net'    => 0.0,
                    'back_paye'      => 0.0,
                    'gross'          => 0.0,
                    'ssnit_employee' => 0.0,
                    'ssnit_employer' => 0.0,
                    'tier2_employer' => 0.0,
                    'tier3_employee' => 0.0,
                    'months'         => [],
                ];
                $acc[$id]['arrears_net']    = round($acc[$id]['arrears_net'] + $arrears, 2);
                $acc[$id]['back_paye']      = round($acc[$id]['back_paye'] + $backPaye, 2);
                $acc[$id]['gross']          = round($acc[$id]['gross'] + $dGross, 2);
                $acc[$id]['ssnit_employee'] = round($acc[$id]['ssnit_employee'] + $dSsnitEmployee, 2);
                $acc[$id]['ssnit_employer'] = round($acc[$id]['ssnit_employer'] + $dSsnitEmployer, 2);
                $acc[$id]['tier2_employer'] = round($acc[$id]['tier2_employer'] + $dTier2, 2);
                $acc[$id]['tier3_employee'] = round($acc[$id]['tier3_employee'] + $dTier3, 2);
                $acc[$id]['months'][]       = [
                    'period'    => $label,
                    'old_basic' => $oldBasic,
                    'new_basic' => $newBasic,
                    'arrears'   => $arrears,
                    'back_paye' => $backPaye,
                ];
            }
        }

        return array_values($acc);
    }

    /** The revised (current) rate for a grade-step at a given month. */
    private function revisedBasic(int $gradeId, int $step, CarbonImmutable $periodDate): ?float
    {
        return Grade::find($gradeId)?->baseSalaryFor($step, $periodDate);
    }
}

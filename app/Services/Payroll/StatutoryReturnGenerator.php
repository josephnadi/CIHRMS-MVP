<?php

namespace App\Services\Payroll;

use App\Enums\StatutoryReturnKind;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Emits GRA / SSNIT / Tier-2 / NHIA / Bank schedules to `storage/app/returns/`.
 *
 * Each file matches the published Ghana statutory schemas (PAYE return for
 * GRA, contribution schedule for SSNIT, per-trustee Tier-2 schedule).
 * Submission state is tracked in `statutory_returns` so an auditor can
 * see which schedules were generated, by whom, and when they were filed.
 */
class StatutoryReturnGenerator
{
    public function generateAll(PayrollRun $run): Collection
    {
        $lines = $run->lines()->with(['employee.user', 'employee.tier2Trustee', 'employee.tier3Trustee'])->calculated()->get();

        $generated = collect();

        $generated->push($this->generatePaye($run, $lines));
        $generated->push($this->generateSsnitTier1($run, $lines));
        $generated->push($this->generateNhiaSplit($run, $lines));
        $generated->push(...$this->generateTier2PerTrustee($run, $lines));
        $generated->push(...$this->generateTier3PerTrustee($run, $lines));
        $generated->push($this->generateBankFile($run, $lines));

        return $generated->filter()->values();
    }

    public function generatePaye(PayrollRun $run, Collection $lines): StatutoryReturn
    {
        $columns = ['TIN', 'Staff ID', 'Full Name', 'Gross', 'SSNIT 5.5%', 'Chargeable', 'PAYE', 'Period'];

        $rows = $lines->map(fn (PayrollLine $line) => [
            $line->employee?->tin_number ?? '',
            $line->employee?->employee_no ?? '',
            $line->employee?->user?->name ?? '',
            $this->fmt($line->gross),
            $this->fmt($line->ssnit_tier1_employee),
            $this->fmt(max((float) $line->gross - (float) $line->ssnit_tier1_employee, 0)),
            $this->fmt($line->paye),
            $run->periodLabel(),
        ])->all();

        return $this->writeFile($run, StatutoryReturnKind::Paye, $columns, $rows, (float) $run->paye_total);
    }

    public function generateSsnitTier1(PayrollRun $run, Collection $lines): StatutoryReturn
    {
        $columns = ['SSNIT No', 'Staff ID', 'Full Name', 'Basic', 'Employer 13%', 'Employee 5.5%', 'Period'];

        $rows = $lines->map(fn (PayrollLine $line) => [
            $line->employee?->ssnit_number ?? '',
            $line->employee?->employee_no ?? '',
            $line->employee?->user?->name ?? '',
            $this->fmt($line->basic),
            $this->fmt($line->ssnit_tier1_employer),
            $this->fmt($line->ssnit_tier1_employee),
            $run->periodLabel(),
        ])->all();

        return $this->writeFile(
            $run,
            StatutoryReturnKind::SsnitTier1,
            $columns,
            $rows,
            (float) $run->ssnit_tier1_employee_total + (float) $run->ssnit_tier1_employer_total,
        );
    }

    public function generateNhiaSplit(PayrollRun $run, Collection $lines): StatutoryReturn
    {
        $columns = ['Staff ID', 'Full Name', 'Basic', 'NHIA 2.5%', 'Period'];

        $rows = $lines->map(fn (PayrollLine $line) => [
            $line->employee?->employee_no ?? '',
            $line->employee?->user?->name ?? '',
            $this->fmt($line->basic),
            $this->fmt($line->nhia_split),
            $run->periodLabel(),
        ])->all();

        return $this->writeFile($run, StatutoryReturnKind::NhiaSplit, $columns, $rows, (float) $run->nhia_total);
    }

    /** @return array<int, StatutoryReturn> */
    public function generateTier2PerTrustee(PayrollRun $run, Collection $lines): array
    {
        $byTrustee = $lines->groupBy(fn (PayrollLine $line) => $line->employee?->tier2_trustee_id ?? 'unassigned');

        $records = [];

        foreach ($byTrustee as $trusteeId => $group) {
            if ($trusteeId === 'unassigned') {
                continue; // Unassigned employees flagged in the run summary, not in returns
            }

            $columns = ['Trustee Reference', 'Staff ID', 'Full Name', 'Basic', 'Tier-2 5%', 'Period'];
            $rows = $group->map(fn (PayrollLine $line) => [
                $line->employee?->tier2Trustee?->npra_license_number ?? '',
                $line->employee?->employee_no ?? '',
                $line->employee?->user?->name ?? '',
                $this->fmt($line->basic),
                $this->fmt($line->tier2_employer),
                $run->periodLabel(),
            ])->all();

            $records[] = $this->writeFile(
                $run,
                StatutoryReturnKind::Tier2Trustee,
                $columns,
                $rows,
                (float) $group->sum('tier2_employer'),
                (int) $trusteeId,
            );
        }

        return $records;
    }

    /** @return array<int, StatutoryReturn> */
    public function generateTier3PerTrustee(PayrollRun $run, Collection $lines): array
    {
        $byTrustee = $lines
            ->filter(fn (PayrollLine $line) => (float) $line->tier3_employee > 0)
            ->groupBy(fn (PayrollLine $line) => $line->employee?->tier3_trustee_id ?? 'unassigned');

        $records = [];

        foreach ($byTrustee as $trusteeId => $group) {
            if ($trusteeId === 'unassigned') {
                continue; // Unassigned employees flagged in the run summary, not in returns
            }

            $columns = ['Trustee Reference', 'Staff ID', 'Full Name', 'Basic', 'Tier-3', 'Period'];
            $rows = $group->map(fn (PayrollLine $line) => [
                $line->employee?->tier3Trustee?->npra_license_number ?? '',
                $line->employee?->employee_no ?? '',
                $line->employee?->user?->name ?? '',
                $this->fmt($line->basic),
                $this->fmt($line->tier3_employee),
                $run->periodLabel(),
            ])->all();

            $records[] = $this->writeFile(
                $run,
                StatutoryReturnKind::Tier3,
                $columns,
                $rows,
                (float) $group->sum('tier3_employee'),
                (int) $trusteeId,
            );
        }

        return $records;
    }

    public function generateBankFile(PayrollRun $run, Collection $lines): StatutoryReturn
    {
        // Ghana Interbank Payment & Settlement Systems (GhIPSS) ACH credit format —
        // simplified to a flat CSV; production export would emit the exact ACH layout.
        $columns = ['Beneficiary Bank', 'Account No', 'Staff ID', 'Full Name', 'Amount', 'Reference'];

        $rows = $lines->map(fn (PayrollLine $line) => [
            $line->employee?->bank_name ?? '',
            $line->employee?->bank_account ?? '',
            $line->employee?->employee_no ?? '',
            $line->employee?->user?->name ?? '',
            $this->fmt($line->net),
            "SAL-{$run->periodLabel()}-{$line->employee?->employee_no}",
        ])->all();

        return $this->writeFile($run, StatutoryReturnKind::BankFile, $columns, $rows, (float) $run->net_total);
    }

    private function writeFile(
        PayrollRun $run,
        StatutoryReturnKind $kind,
        array $columns,
        array $rows,
        float $total,
        ?int $trusteeId = null,
    ): StatutoryReturn {
        $filename = sprintf(
            'returns/%04d/%02d/%s_%s%s.%s',
            $run->period_year,
            $run->period_month,
            $run->reference,
            $kind->value,
            $trusteeId ? "_t{$trusteeId}" : '',
            $kind->fileExtension(),
        );

        $csv = $this->toCsv($columns, $rows);
        Storage::disk('local')->put($filename, $csv);

        return StatutoryReturn::create([
            'payroll_run_id' => $run->id,
            'kind'           => $kind->value,
            'trustee_id'     => $trusteeId,
            'file_path'      => $filename,
            'total_amount'   => round($total, 2),
            'record_count'   => count($rows),
            'generated_at'   => now(),
        ]);
    }

    private function toCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);
        return $contents;
    }

    private function fmt($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

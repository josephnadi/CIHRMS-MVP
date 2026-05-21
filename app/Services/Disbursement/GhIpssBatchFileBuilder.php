<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the GhIPSS Direct Credit / ACH bulk-payment file for a payroll run.
 *
 * The output is a CSV in a canonical layout that every Ghanaian sponsor bank
 * accepts (GCB Bank's ECP, Stanbic's bulk-pay, Ecobank's omni-bulk, etc. all
 * map cleanly from these fields). When deploying to a specific bank that
 * insists on its own column order, override `headerRow()` + `dataRow()` in
 * a per-bank subclass — the rest of the file/storage plumbing reuses.
 *
 * Layout (one header row + one row per beneficiary):
 *   sequence_no, beneficiary_account, beneficiary_bank_sort_code,
 *   beneficiary_name, amount_ghs, narration, reference, originator_name,
 *   originator_sort_code, value_date
 *
 * Amounts are in GHS with two decimals (not pesewas) — Ghanaian banks
 * uniformly accept the decimal form, and storing pesewas as integers
 * makes the CSV harder to inspect when something goes wrong.
 */
class GhIpssBatchFileBuilder
{
    public function __construct(
        private readonly string $sponsorSortCode,
        private readonly string $originatorName,
        private readonly string $disk = 'local',
    ) {}

    /**
     * Build the file for one run and return the storage path it landed at.
     *
     *   storage/app/ghipss-batches/PR-{run_id}-{YYYYMMDD-HHMMSS}.csv
     *
     * Only disbursements with channel=ghipss_ach and status in {Sent, Settled}
     * are exported. Pending rows haven't been staged yet; failed rows are
     * excluded because we don't want to re-instruct on them.
     */
    public function build(PayrollRun $run): string
    {
        $rows = Disbursement::query()
            ->where('payroll_run_id', $run->id)
            ->where('channel', DisbursementChannel::GhipssAch->value)
            ->whereIn('status', [DisbursementStatus::Sent->value, DisbursementStatus::Settled->value])
            ->with(['employee.user'])
            ->orderBy('id')
            ->get();

        $filename = sprintf(
            'ghipss-batches/PR-%d-%s.csv',
            $run->id,
            Carbon::now()->format('Ymd-His'),
        );

        $valueDate = optional($run->pay_date ?? $run->period_end)->format('Y-m-d') ?? now()->toDateString();

        $csv = [];
        $csv[] = $this->headerRow();

        $seq = 0;
        foreach ($rows as $row) {
            $seq++;
            $csv[] = $this->dataRow($seq, $row, $valueDate);
        }

        $contents = $this->joinCsv($csv);
        Storage::disk($this->disk)->put($filename, $contents);

        return $filename;
    }

    /**
     * Convenience for tests + admin previews — same output, no file write.
     */
    public function preview(PayrollRun $run): string
    {
        $rows = Disbursement::query()
            ->where('payroll_run_id', $run->id)
            ->where('channel', DisbursementChannel::GhipssAch->value)
            ->whereIn('status', [DisbursementStatus::Sent->value, DisbursementStatus::Settled->value])
            ->with(['employee.user'])
            ->orderBy('id')
            ->get();

        $valueDate = optional($run->pay_date ?? $run->period_end)->format('Y-m-d') ?? now()->toDateString();

        $csv = [$this->headerRow()];
        $seq = 0;
        foreach ($rows as $row) {
            $seq++;
            $csv[] = $this->dataRow($seq, $row, $valueDate);
        }
        return $this->joinCsv($csv);
    }

    /** Column headers — first line of the file. */
    protected function headerRow(): array
    {
        return [
            'sequence_no',
            'beneficiary_account',
            'beneficiary_bank_sort_code',
            'beneficiary_name',
            'amount_ghs',
            'narration',
            'reference',
            'originator_name',
            'originator_sort_code',
            'value_date',
        ];
    }

    /** One CSV row per Disbursement. */
    protected function dataRow(int $sequence, Disbursement $d, string $valueDate): array
    {
        $employee = $d->employee;
        // Sort code lives on the Employee row (per-bank, 5 or 6 digits).
        $sortCode = (string) ($employee?->bank_sort_code ?? '');

        return [
            (string) $sequence,
            $this->sanitize((string) $d->beneficiary_account),
            $sortCode,
            $this->sanitize((string) ($d->beneficiary_name ?? $employee?->user?->name ?? '')),
            number_format((float) $d->net_to_recipient, 2, '.', ''),
            $this->narration($d),
            (string) ($d->provider_reference ?? sprintf('GHIPSS-%d-%d', $d->payroll_run_id, $d->id)),
            $this->originatorName,
            $this->sponsorSortCode,
            $valueDate,
        ];
    }

    /** Bank statement narration — keep under 35 chars (ACH standard limit). */
    private function narration(Disbursement $d): string
    {
        $employeeNo = $d->employee?->employee_no ?? '';
        $base = "PAYROLL {$employeeNo}";
        return mb_substr($this->sanitize($base), 0, 35);
    }

    /** Strip commas + quotes + CR/LF so CSV parsing on the bank side never fights us. */
    private function sanitize(string $value): string
    {
        return trim(preg_replace('/[",\r\n]+/', ' ', $value) ?? $value);
    }

    /**
     * Join rows into a CRLF-terminated CSV. CRLF is what every bank's parser
     * accepts; LF-only files occasionally tripped up older Ecobank gateways.
     */
    private function joinCsv(array $rows): string
    {
        return implode("\r\n", array_map(
            fn ($row) => implode(',', array_map(fn ($cell) => (string) $cell, $row)),
            $rows,
        )) . "\r\n";
    }
}

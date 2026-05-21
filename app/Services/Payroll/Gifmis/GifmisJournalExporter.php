<?php

declare(strict_types=1);

namespace App\Services\Payroll\Gifmis;

use App\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * GIFMIS sub-ledger journal voucher export.
 *
 * Produces the CSV the Ghana Integrated Financial Management Information
 * System (GIFMIS) accepts for bulk-loading payroll journals. Each closed
 * payroll run mints exactly one balanced double-entry journal:
 *
 *   Debits  — Salary expense, employer Tier-1, employer Tier-2 (cost-centre level)
 *   Credits — Net-pay payable, PAYE payable, SSNIT Tier-1 employee payable,
 *             SSNIT Tier-1 employer payable, Tier-2 payable, Tier-3 payable,
 *             NHIA payable, voluntary deductions payable
 *
 * Total debits === total credits, always. The exporter throws if they don't
 * — a non-zero residual means a calculator bug, and shipping an unbalanced
 * JV would be a state-accountant-rejecting embarrassment.
 *
 * The GL account codes live in `config/payroll.php` under `gifmis.gl_codes`
 * so each MDA can override their specific CAGD chart-of-accounts entries
 * without forking the service.
 *
 * File format: pipe-delimited CSV with one header + N journal lines.
 * Columns: journal_id | line_no | gl_code | cost_centre | dr_amount |
 *          cr_amount | narration | period | source_doc | reference
 *
 * Amounts are emitted in GHS with 2 decimals (GIFMIS prefers decimal over
 * pesewa-integer, unlike IPPD).
 */
class GifmisJournalExporter
{
    public function __construct(
        /** Cost-centre code printed against every detail line (institution-level). */
        private readonly string $costCentre,
        /**
         * GL codes per posting line.
         * @var array{
         *   dr_salary: string,
         *   dr_ssnit_employer: string,
         *   dr_tier2_employer: string,
         *   cr_net_payable: string,
         *   cr_paye: string,
         *   cr_ssnit_employee: string,
         *   cr_ssnit_employer: string,
         *   cr_tier2: string,
         *   cr_tier3: string,
         *   cr_nhia: string,
         *   cr_voluntary: string,
         * }
         */
        private readonly array $glCodes,
        private readonly string $disk = 'local',
    ) {}

    public function build(PayrollRun $run): string
    {
        $filename = sprintf(
            'gifmis-journals/JV-%d-%s.csv',
            $run->id,
            Carbon::now()->format('Ymd-His'),
        );
        Storage::disk($this->disk)->put($filename, $this->compose($run));
        return $filename;
    }

    /** Preview is what tests + the HTTP download endpoint both call. */
    public function preview(PayrollRun $run): string
    {
        return $this->compose($run);
    }

    /**
     * Build the journal as a structured array — useful for callers that want
     * to inspect lines before serialising, or for non-CSV serialisers.
     *
     * @return array{header: array, lines: array<int, array>, totals: array{debit: float, credit: float}}
     */
    public function journal(PayrollRun $run): array
    {
        $totals = $this->totals($run);

        $period      = sprintf('%04d-%02d', $run->period_year, $run->period_month);
        $journalId   = "JV-{$run->reference}";
        $reference   = $run->reference;
        $narrationOf = fn (string $what) => "Payroll {$period} — {$what}";
        $sourceDoc   = "PayrollRun#{$run->id}";

        $lines = [];
        $line  = 0;

        // ── DEBITS ─────────────────────────────────────────────────────
        // Salary expense = gross pay (employees' earned wages). This is the
        // expense that hits the income statement; everything below it nets
        // against it via the payable accounts.
        if ($totals['gross'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['dr_salary'],
                dr: $totals['gross'], cr: 0.0,
                narration: $narrationOf('Salary expense'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        // Employer SSNIT Tier-1 (employer's 13% share, of which 2.5% is NHIA-routed).
        if ($totals['ssnit_employer'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['dr_ssnit_employer'],
                dr: $totals['ssnit_employer'], cr: 0.0,
                narration: $narrationOf('Employer SSNIT Tier-1 expense'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        // Employer Tier-2 (5% mandatory NPRA-trustee contribution).
        if ($totals['tier2_employer'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['dr_tier2_employer'],
                dr: $totals['tier2_employer'], cr: 0.0,
                narration: $narrationOf('Employer Tier-2 pension expense'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        // ── CREDITS ────────────────────────────────────────────────────
        // Net pay payable — what we actually owe to disburse to employees.
        if ($totals['net'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_net_payable'],
                dr: 0.0, cr: $totals['net'],
                narration: $narrationOf('Net-pay payable (employee)'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        if ($totals['paye'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_paye'],
                dr: 0.0, cr: $totals['paye'],
                narration: $narrationOf('PAYE payable (GRA)'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        if ($totals['ssnit_employee'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_ssnit_employee'],
                dr: 0.0, cr: $totals['ssnit_employee'],
                narration: $narrationOf('SSNIT Tier-1 (employee)'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        if ($totals['ssnit_employer_credit'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_ssnit_employer'],
                dr: 0.0, cr: $totals['ssnit_employer_credit'],
                narration: $narrationOf('SSNIT Tier-1 (employer)'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        if ($totals['nhia'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_nhia'],
                dr: 0.0, cr: $totals['nhia'],
                narration: $narrationOf('NHIA payable'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        if ($totals['tier2'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_tier2'],
                dr: 0.0, cr: $totals['tier2'],
                narration: $narrationOf('Tier-2 pension payable (trustee)'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        if ($totals['tier3'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_tier3'],
                dr: 0.0, cr: $totals['tier3'],
                narration: $narrationOf('Tier-3 voluntary pension payable'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        if ($totals['voluntary'] > 0.0) {
            $lines[] = $this->row(++$line, $journalId, $this->glCodes['cr_voluntary'],
                dr: 0.0, cr: $totals['voluntary'],
                narration: $narrationOf('Voluntary deductions payable'),
                period: $period, sourceDoc: $sourceDoc, reference: $reference);
        }

        $debitTotal  = round(array_sum(array_column($lines, 'dr_amount')), 2);
        $creditTotal = round(array_sum(array_column($lines, 'cr_amount')), 2);

        if (abs($debitTotal - $creditTotal) > 0.01) {
            throw new RuntimeException(sprintf(
                'GIFMIS journal not balanced for PayrollRun#%d — debit total %.2f vs credit total %.2f. ' .
                'This indicates an upstream calculator residual; investigate before exporting.',
                $run->id, $debitTotal, $creditTotal,
            ));
        }

        return [
            'header' => [
                'journal_id'    => $journalId,
                'period'        => $period,
                'reference'     => $reference,
                'source_doc'    => $sourceDoc,
                'generated_at'  => now()->toIso8601String(),
                'cost_centre'   => $this->costCentre,
            ],
            'lines'  => $lines,
            'totals' => ['debit' => $debitTotal, 'credit' => $creditTotal],
        ];
    }

    private function compose(PayrollRun $run): string
    {
        $journal = $this->journal($run);

        $out = [];
        $out[] = $this->headerRow();
        foreach ($journal['lines'] as $line) {
            $out[] = implode('|', [
                $line['journal_id'],
                $line['line_no'],
                $line['gl_code'],
                $line['cost_centre'],
                number_format($line['dr_amount'], 2, '.', ''),
                number_format($line['cr_amount'], 2, '.', ''),
                $this->sanitize($line['narration']),
                $line['period'],
                $line['source_doc'],
                $line['reference'],
            ]);
        }
        // Trailer with totals for the GIFMIS reconciler.
        $out[] = implode('|', [
            '*TOTALS*',
            (string) count($journal['lines']),
            '', '',
            number_format($journal['totals']['debit'],  2, '.', ''),
            number_format($journal['totals']['credit'], 2, '.', ''),
            'Sum debits === Sum credits',
            $journal['header']['period'],
            $journal['header']['source_doc'],
            $journal['header']['reference'],
        ]);

        return implode("\r\n", $out) . "\r\n";
    }

    private function headerRow(): string
    {
        return implode('|', [
            'journal_id', 'line_no', 'gl_code', 'cost_centre',
            'dr_amount', 'cr_amount', 'narration',
            'period', 'source_doc', 'reference',
        ]);
    }

    private function row(int $lineNo, string $journalId, string $glCode, float $dr, float $cr,
                        string $narration, string $period, string $sourceDoc, string $reference): array
    {
        return [
            'journal_id'  => $journalId,
            'line_no'     => $lineNo,
            'gl_code'     => $glCode,
            'cost_centre' => $this->costCentre,
            'dr_amount'   => round($dr, 2),
            'cr_amount'   => round($cr, 2),
            'narration'   => $narration,
            'period'      => $period,
            'source_doc'  => $sourceDoc,
            'reference'   => $reference,
        ];
    }

    private function totals(PayrollRun $run): array
    {
        $sum = fn (string $col) => (float) $run->lines()->sum($col);

        $ssnitEmployer = $sum('ssnit_tier1_employer');
        $nhia          = $sum('nhia_split');

        // The NHIA portion is part of the employer's 13% — we already include
        // ssnit_tier1_employer as a debit, so the matching credit must split:
        //   ssnit_tier1_employer total = nhia_split + ssnit-Tier1-employer-net.
        // We post NHIA on its own GL and reduce the SSNIT-employer credit to
        // what's left over, keeping the JV balanced.
        $ssnitEmployerCredit = max(0.0, $ssnitEmployer - $nhia);

        return [
            'gross'                  => $sum('gross'),
            'net'                    => $sum('net'),
            'paye'                   => $sum('paye'),
            'ssnit_employee'         => $sum('ssnit_tier1_employee'),
            'ssnit_employer'         => $ssnitEmployer,
            'ssnit_employer_credit'  => $ssnitEmployerCredit,
            'nhia'                   => $nhia,
            'tier2_employer'         => $sum('tier2_employer'),
            'tier2'                  => $sum('tier2_employer'),
            'tier3'                  => $sum('tier3_employee'),
            'voluntary'              => $sum('voluntary_deductions'),
        ];
    }

    private function sanitize(string $value): string
    {
        return trim(preg_replace('/[|\r\n]+/', ' ', $value) ?? $value);
    }
}

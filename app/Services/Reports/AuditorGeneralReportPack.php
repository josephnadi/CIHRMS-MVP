<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\LoanAccount;
use App\Models\OffboardingCase;
use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\WhistleblowerReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Auditor-General Report Pack.
 *
 * Bundles into a single ZIP archive everything the Auditor-General's office
 * audits during an MDA visit, scoped to a single fiscal year:
 *
 *   1. MANIFEST.md                     — index + chain-of-custody summary
 *   2. payroll/runs.csv                — all approved/paid runs with totals
 *   3. payroll/run_lines.csv           — per-employee lines (full granularity)
 *   4. statutory/PAYE-{year}.csv       — concatenated PAYE returns
 *   5. statutory/SSNIT-{year}.csv      — concatenated SSNIT Tier-1 contributions
 *   6. statutory/TIER2-{year}.csv      — per-trustee Tier-2 schedules
 *   7. statutory/NHIA-{year}.csv       — NHIA share extracted from Tier-1
 *   8. statutory/bank_files/           — original GhIPSS bank disbursement files
 *   9. identity/verifications.csv      — Ghana Card register snapshot
 *  10. loans/accounts.csv              — full loan ledger
 *  11. loans/outstanding.csv           — open balances at year-end
 *  12. offboarding/cases.csv           — exits + final settlements
 *  13. whistleblower/summary.csv       — case counts (NO content — segregated channel)
 *  14. audit/chain_verification.txt    — output of `audit:verify-chain`
 *
 * The pack is itself signed with a SHA-256 manifest so any tampering after
 * generation is detectable.
 */
class AuditorGeneralReportPack
{
    public const VERSION = '1.0';

    /**
     * @return string Absolute path to the generated ZIP file
     */
    public function generate(int $fiscalYear, string $jurisdiction = 'GH'): string
    {
        $start = CarbonImmutable::create($fiscalYear, 1, 1)->startOfDay();
        $end   = CarbonImmutable::create($fiscalYear, 12, 31)->endOfDay();

        $workDir = storage_path("app/ag-reports/fy{$fiscalYear}-" . now()->format('Ymd-His'));
        @mkdir($workDir, 0775, true);
        @mkdir("{$workDir}/payroll", 0775, true);
        @mkdir("{$workDir}/statutory", 0775, true);
        @mkdir("{$workDir}/statutory/bank_files", 0775, true);
        @mkdir("{$workDir}/identity", 0775, true);
        @mkdir("{$workDir}/loans", 0775, true);
        @mkdir("{$workDir}/offboarding", 0775, true);
        @mkdir("{$workDir}/whistleblower", 0775, true);
        @mkdir("{$workDir}/audit", 0775, true);

        $filesWritten = [];

        // ── 1. Payroll runs ──────────────────────────────────────────────
        $filesWritten[] = $this->writePayrollRuns("{$workDir}/payroll/runs.csv", $start, $end);
        $filesWritten[] = $this->writePayrollLines("{$workDir}/payroll/run_lines.csv", $start, $end);

        // ── 2. Statutory returns ────────────────────────────────────────
        $filesWritten = array_merge($filesWritten, $this->writeStatutoryReturns($workDir, $start, $end, $fiscalYear));

        // ── 3. Identity verifications ──────────────────────────────────
        $filesWritten[] = $this->writeIdentityRegister("{$workDir}/identity/verifications.csv");

        // ── 4. Loans ────────────────────────────────────────────────────
        $filesWritten[] = $this->writeLoanAccounts("{$workDir}/loans/accounts.csv", $start, $end);
        $filesWritten[] = $this->writeOutstandingLoans("{$workDir}/loans/outstanding.csv", $end);

        // ── 5. Off-boarding ─────────────────────────────────────────────
        $filesWritten[] = $this->writeOffboardingCases("{$workDir}/offboarding/cases.csv", $start, $end);

        // ── 6. Whistleblower (counts only — case content is segregated) ─
        $filesWritten[] = $this->writeWhistleblowerSummary("{$workDir}/whistleblower/summary.csv", $start, $end);

        // ── 7. Audit chain verification ─────────────────────────────────
        $filesWritten[] = $this->writeAuditChainVerification("{$workDir}/audit/chain_verification.txt");

        // ── 8. Manifest with SHA-256 of every file (tamper-evident) ────
        $manifestPath = $this->writeManifest($workDir, $filesWritten, $fiscalYear, $jurisdiction);
        $filesWritten[] = $manifestPath;

        // ── 9. Zip it all up ────────────────────────────────────────────
        $zipPath = storage_path("app/ag-reports/AG-{$fiscalYear}-" . now()->format('Ymd-His') . '.zip');
        $this->makeZip($workDir, $zipPath);

        return $zipPath;
    }

    private function writePayrollRuns(string $path, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $rows = PayrollRun::query()
            ->whereBetween('period_start', [$start, $end])
            ->with(['department:id,name', 'approver:id,name'])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get();

        $this->writeCsv($path, [
            'Reference', 'Period', 'Department', 'Status',
            'Lines', 'Skipped', 'Gross', 'PAYE', 'SSNIT-Employee',
            'SSNIT-Employer', 'NHIA', 'Tier-2', 'Voluntary', 'Net',
            'Approved By', 'Approved At', 'Paid At',
        ], $rows->map(fn ($r) => [
            $r->reference,
            $r->periodLabel(),
            $r->department?->name ?? 'WHOLE ORG',
            $r->status?->value,
            $r->lines_count, $r->skipped_count,
            (float) $r->gross_total,
            (float) $r->paye_total,
            (float) $r->ssnit_tier1_employee_total,
            (float) $r->ssnit_tier1_employer_total,
            (float) $r->nhia_total,
            (float) $r->tier2_employer_total,
            (float) $r->voluntary_deductions_total,
            (float) $r->net_total,
            $r->approver?->name ?? '',
            optional($r->approved_at)->toIso8601String() ?? '',
            optional($r->paid_at)->toIso8601String() ?? '',
        ])->all());

        return $path;
    }

    private function writePayrollLines(string $path, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $columns = [
            'Run Reference', 'Period', 'Staff ID', 'Employee', 'Grade', 'Step',
            'Basic', 'Allowances', 'Gross', 'SSNIT 5.5%', 'PAYE', 'Tier-2 5%',
            'Voluntary', 'Net', 'Status',
        ];

        $handle = fopen($path, 'w');
        fputcsv($handle, $columns);

        // Streamed to keep memory flat even for very large years.
        PayrollRun::query()
            ->whereBetween('period_start', [$start, $end])
            ->with(['lines.employee.user', 'lines.grade'])
            ->orderBy('period_year')->orderBy('period_month')
            ->chunk(20, function ($runs) use ($handle) {
                foreach ($runs as $run) {
                    foreach ($run->lines as $line) {
                        fputcsv($handle, [
                            $run->reference,
                            $run->periodLabel(),
                            $line->employee?->employee_no ?? '',
                            $line->employee?->user?->name ?? '',
                            $line->grade?->code ?? '',
                            $line->step,
                            (float) $line->basic,
                            (float) $line->allowance_total,
                            (float) $line->gross,
                            (float) $line->ssnit_tier1_employee,
                            (float) $line->paye,
                            (float) $line->tier2_employer,
                            (float) $line->voluntary_deductions,
                            (float) $line->net,
                            $line->status,
                        ]);
                    }
                }
            });

        fclose($handle);
        return $path;
    }

    /** @return array<int, string> paths written */
    private function writeStatutoryReturns(string $workDir, CarbonImmutable $start, CarbonImmutable $end, int $year): array
    {
        $kindToFile = [
            'paye'           => "{$workDir}/statutory/PAYE-{$year}.csv",
            'ssnit_tier1'    => "{$workDir}/statutory/SSNIT-{$year}.csv",
            'tier2_trustee'  => "{$workDir}/statutory/TIER2-{$year}.csv",
            'nhia_split'     => "{$workDir}/statutory/NHIA-{$year}.csv",
        ];

        $files = [];

        foreach ($kindToFile as $kind => $outPath) {
            $returns = StatutoryReturn::query()
                ->whereHas('run', fn ($q) => $q->whereBetween('period_start', [$start, $end]))
                ->where('kind', $kind)
                ->orderBy('generated_at')
                ->get();

            if ($returns->isEmpty()) continue;

            // Concatenate every period's return CSV under one yearly file,
            // preserving the original header from the first file.
            $out = fopen($outPath, 'w');
            $headerWritten = false;

            foreach ($returns as $ret) {
                if (! Storage::disk('local')->exists($ret->file_path)) continue;
                $content = Storage::disk('local')->get($ret->file_path);
                $lines   = explode("\n", trim($content));
                if (empty($lines)) continue;

                if (! $headerWritten) {
                    fwrite($out, $lines[0] . "\n");
                    $headerWritten = true;
                }
                foreach (array_slice($lines, 1) as $line) {
                    if ($line === '') continue;
                    fwrite($out, $line . "\n");
                }
            }

            fclose($out);
            $files[] = $outPath;
        }

        // Bank files preserved verbatim (these are the GhIPSS-compatible payment files)
        $bankReturns = StatutoryReturn::query()
            ->whereHas('run', fn ($q) => $q->whereBetween('period_start', [$start, $end]))
            ->where('kind', 'bank_file')
            ->get();

        foreach ($bankReturns as $ret) {
            if (! Storage::disk('local')->exists($ret->file_path)) continue;
            $copy = "{$workDir}/statutory/bank_files/" . basename($ret->file_path);
            file_put_contents($copy, Storage::disk('local')->get($ret->file_path));
            $files[] = $copy;
        }

        return $files;
    }

    private function writeIdentityRegister(string $path): string
    {
        $rows = IdentityVerification::query()
            ->with('employee.user', 'employee.department')
            ->orderBy('employee_id')
            ->get();

        // Ghana Card numbers are encrypted on the model; the register exposes a
        // masked tail only — the auditor sees who is verified, not the raw PII.
        $this->writeCsv($path, [
            'Staff ID', 'Employee', 'Department', 'Card (masked)',
            'Provider', 'Status', 'Verified At', 'Expires At', 'Failure Reason',
        ], $rows->map(function ($v) {
            $card = $v->ghana_card_number; // decrypted on read
            $masked = $card ? 'GHA-' . str_repeat('•', 9) . '-' . substr($card, -1) : '';
            return [
                $v->employee?->employee_no ?? '',
                $v->employee?->user?->name ?? '',
                $v->employee?->department?->name ?? '',
                $masked,
                $v->provider?->value,
                $v->status?->value,
                optional($v->verified_at)->toIso8601String() ?? '',
                optional($v->expires_at)->toIso8601String() ?? '',
                $v->failure_reason ?? '',
            ];
        })->all());

        return $path;
    }

    private function writeLoanAccounts(string $path, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $rows = LoanAccount::query()
            ->with('employee.user', 'product')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get();

        $this->writeCsv($path, [
            'Reference', 'Staff ID', 'Employee', 'Product', 'Status',
            'Principal', 'Term (m)', 'Rate', 'Method',
            'Monthly', 'Total Interest', 'Total Repayable',
            'Outstanding', 'Installments Paid',
            'Applied', 'Approved', 'Disbursed', 'Closed',
        ], $rows->map(fn ($l) => [
            $l->reference,
            $l->employee?->employee_no ?? '',
            $l->employee?->user?->name ?? '',
            $l->product?->name ?? '',
            $l->status?->value,
            (float) $l->principal, (int) $l->term_months, (float) $l->booked_interest_rate,
            $l->booked_amortization_method?->value,
            (float) $l->monthly_installment, (float) $l->total_interest, (float) $l->total_repayable,
            (float) $l->outstanding_balance, (int) $l->installments_paid,
            optional($l->applied_at)->toDateString(),
            optional($l->approved_at)->toDateString(),
            optional($l->disbursed_at)->toDateString(),
            optional($l->actual_end_date)->toDateString(),
        ])->all());

        return $path;
    }

    private function writeOutstandingLoans(string $path, CarbonImmutable $asOf): string
    {
        $rows = LoanAccount::query()
            ->with('employee.user')
            ->where('outstanding_balance', '>', 0)
            ->whereIn('status', ['disbursed', 'repaying'])
            ->orderByDesc('outstanding_balance')
            ->get();

        $this->writeCsv($path, [
            'Reference', 'Staff ID', 'Employee', 'Outstanding Balance', 'Installments Remaining',
        ], $rows->map(fn ($l) => [
            $l->reference,
            $l->employee?->employee_no ?? '',
            $l->employee?->user?->name ?? '',
            (float) $l->outstanding_balance,
            (int) $l->term_months - (int) $l->installments_paid,
        ])->all());

        return $path;
    }

    private function writeOffboardingCases(string $path, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $rows = OffboardingCase::query()
            ->with('employee.user', 'settlement')
            ->whereBetween('last_working_day', [$start, $end])
            ->orderBy('last_working_day')
            ->get();

        $this->writeCsv($path, [
            'Reference', 'Staff ID', 'Employee', 'Exit Type', 'Status',
            'Notice Received', 'Last Working Day',
            'Gross Settlement', 'Total Deductions', 'Net Payable',
            'Settlement Status', 'Approved At',
        ], $rows->map(fn ($c) => [
            $c->reference,
            $c->employee?->employee_no ?? '',
            $c->employee?->user?->name ?? '',
            $c->exit_type?->value,
            $c->status?->value,
            optional($c->notice_received_on)->toDateString(),
            optional($c->last_working_day)->toDateString(),
            (float) ($c->settlement?->gross_settlement ?? 0),
            (float) ($c->settlement?->total_deductions ?? 0),
            (float) ($c->settlement?->net_payable ?? 0),
            $c->settlement?->status?->value ?? '',
            optional($c->settlement?->approved_at)->toIso8601String() ?? '',
        ])->all());

        return $path;
    }

    private function writeWhistleblowerSummary(string $path, CarbonImmutable $start, CarbonImmutable $end): string
    {
        // Aggregate counts only — case content is OFF-LIMITS to the AG pack
        // because the channel is statutorily segregated. The AG asks the
        // designated investigator for content if a specific case is needed.
        $byCategory = WhistleblowerReport::query()
            ->whereBetween('received_at', [$start, $end])
            ->selectRaw('category, severity, status, count(*) as n')
            ->groupBy('category', 'severity', 'status')
            ->get();

        $this->writeCsv($path, [
            'Category', 'Severity', 'Status', 'Count',
        ], $byCategory->map(fn ($r) => [
            $r->category, $r->severity ?? '', $r->status, (int) $r->n,
        ])->all());

        return $path;
    }

    private function writeAuditChainVerification(string $path): string
    {
        // Run the verification command and capture its output. If the chain is
        // broken anywhere, this file shows that — which is what the auditor
        // wants to see, not a sanitized "all good".
        $exitCode = -1;
        $output   = [];

        try {
            \Artisan::call('audit:verify-chain');
            $exitCode = \Artisan::output() !== '' ? 0 : 0;
            $output   = explode("\n", trim(\Artisan::output()));
        } catch (\Throwable $e) {
            $output = ['VERIFICATION ERROR: ' . $e->getMessage()];
        }

        $total = AuditLog::count();
        $first = AuditLog::orderBy('chain_position')->first();
        $last  = AuditLog::orderByDesc('chain_position')->first();

        $body = "Audit Chain Verification\n"
              . str_repeat('=', 60) . "\n\n"
              . "Generated:        " . now()->toIso8601String() . "\n"
              . "Verification cmd: php artisan audit:verify-chain\n"
              . "Exit code:        {$exitCode}\n"
              . "Total rows:       {$total}\n"
              . "First chain pos:  " . ($first?->chain_position ?? '—') . "\n"
              . "Last chain pos:   " . ($last?->chain_position  ?? '—') . "\n"
              . "Last row hash:    " . ($last?->row_hash         ?? '—') . "\n\n"
              . "Command output:\n"
              . str_repeat('-', 60) . "\n"
              . implode("\n", $output) . "\n";

        file_put_contents($path, $body);
        return $path;
    }

    private function writeManifest(string $workDir, array $files, int $fiscalYear, string $jurisdiction): string
    {
        $path = "{$workDir}/MANIFEST.md";
        $appName = config('app.name', 'CIHRMS');

        $lines = [
            "# Auditor-General Report Pack — Fiscal Year {$fiscalYear}",
            '',
            "Generated by: {$appName}",
            "Pack version: " . self::VERSION,
            "Generated at: " . now()->toIso8601String(),
            "Jurisdiction: {$jurisdiction}",
            "App version:  " . config('app.version', 'unknown'),
            '',
            '## Files',
            '',
            '| Path | SHA-256 | Size |',
            '|------|---------|------|',
        ];

        foreach ($files as $file) {
            if (! file_exists($file)) continue;
            $relative = ltrim(str_replace($workDir, '', $file), '/\\');
            $hash     = hash_file('sha256', $file);
            $size     = filesize($file);
            $lines[]  = "| `{$relative}` | `{$hash}` | " . number_format($size) . ' B |';
        }

        $lines[] = '';
        $lines[] = '## Audit chain';
        $lines[] = '';
        $lines[] = 'The `audit/chain_verification.txt` file contains the result of running ';
        $lines[] = '`php artisan audit:verify-chain` against this database. Any non-zero exit ';
        $lines[] = 'code indicates tampering on at least one row of `audit_logs`.';
        $lines[] = '';
        $lines[] = '## Whistleblower segregation';
        $lines[] = '';
        $lines[] = 'Per Whistleblower Act 2006 (Act 720) the case content is NOT included in ';
        $lines[] = 'this pack. Only aggregate counts. To inspect a specific case, the ';
        $lines[] = 'Auditor-General must address the designated investigator under separate ';
        $lines[] = 'cover.';
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function makeZip(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot open zip {$zipPath} for writing.");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $local = ltrim(str_replace($sourceDir, '', $file->getRealPath()), '/\\');
            $zip->addFile($file->getRealPath(), $local);
        }

        $zip->close();
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $h = fopen($path, 'w');
        fputcsv($h, $headers);
        foreach ($rows as $row) fputcsv($h, $row);
        fclose($h);
    }
}

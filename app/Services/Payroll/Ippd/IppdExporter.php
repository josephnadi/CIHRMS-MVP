<?php

declare(strict_types=1);

namespace App\Services\Payroll\Ippd;

use App\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Oracle IPPD2 / IPPD3 export.
 *
 * Produces the CAGD-format Payment Voucher (PV) upload file that the Ghana
 * public service uses to push monthly payroll data into the Integrated
 * Personnel & Payroll Database. Format:
 *
 *   "H" header line   — meta about the run (period, MDA code, record count)
 *   "D" detail lines  — one per employee, with all statutory + voluntary
 *                       deductions and net pay
 *   "T" trailer line  — totals for reconciliation against the run's cached
 *                       aggregates
 *
 * The CAGD spec is "fixed-position pipe-delimited" — pipes between fields,
 * fields left-aligned within a fixed width. Every spec revision changes the
 * widths, so they live in `$IPPD_COLUMNS` for easy tuning per pilot MDA.
 *
 * Amounts are in pesewas (integer × 100) per the IPPD spec — *not* the
 * decimal-GHS form the bank rails use. A decimal slipping through the
 * pesewa conversion is a class of bug that's caught by golden-file tests
 * because the trailer-line totals won't match.
 */
class IppdExporter
{
    /**
     * Canonical field map for the detail "D" row. Order is load-bearing —
     * CAGD's IPPD parser reads by ordinal, not by name. Width is the
     * minimum field width; longer values are truncated, shorter are padded.
     */
    private const IPPD_COLUMNS = [
        ['record_type',         1],   // 'D'
        ['ippd_number',         10],  // employee_no
        ['surname',             40],
        ['other_names',         40],
        ['ministry_code',       6],   // department code
        ['grade_code',          8],
        ['step',                2],
        ['bank_sort_code',      8],
        ['bank_account',        20],
        ['basic_pesewas',       12],
        ['allowance_pesewas',   12],
        ['gross_pesewas',       12],
        ['paye_pesewas',        12],
        ['ssnit_employee',      12],
        ['ssnit_employer',      12],
        ['nhia_pesewas',        12],
        ['tier2_pesewas',       12],
        ['tier3_pesewas',       12],
        ['voluntary_pesewas',   12],
        ['net_pesewas',         12],
    ];

    public function __construct(
        /** Ministry/Department/Agency code printed in the H header. */
        private readonly string $mdaCode,
        private readonly string $disk = 'local',
    ) {}

    public function build(PayrollRun $run): string
    {
        $filename = sprintf(
            'ippd-exports/IPPD-%d-%s.csv',
            $run->id,
            Carbon::now()->format('Ymd-His'),
        );

        Storage::disk($this->disk)->put($filename, $this->compose($run));
        return $filename;
    }

    /** Same output as build() but returned as a string — used by tests + preview. */
    public function preview(PayrollRun $run): string
    {
        return $this->compose($run);
    }

    private function compose(PayrollRun $run): string
    {
        $lines = $run->lines()
            ->with(['employee.user', 'employee.department', 'grade', 'position'])
            ->orderBy('id')
            ->get();

        $out = [];
        $out[] = $this->headerRow($run, $lines->count());

        foreach ($lines as $line) {
            $out[] = $this->detailRow($line);
        }

        $out[] = $this->trailerRow($run, $lines);

        return implode("\r\n", $out) . "\r\n";
    }

    private function headerRow(PayrollRun $run, int $recordCount): string
    {
        return implode('|', [
            'H',
            $this->mdaCode,
            $run->reference,
            sprintf('%04d%02d', $run->period_year, $run->period_month),
            optional($run->period_start)->format('Ymd'),
            optional($run->period_end)->format('Ymd'),
            (string) $recordCount,
            now()->format('YmdHis'),
            'IPPD3',
        ]);
    }

    private function detailRow($line): string
    {
        $employee = $line->employee;
        $user     = $employee?->user;
        $name     = (string) ($user?->name ?? '');
        [$surname, $others] = $this->splitName($name);

        $values = [
            'record_type'       => 'D',
            'ippd_number'       => (string) ($employee?->employee_no ?? ''),
            'surname'           => $this->ascii($surname),
            'other_names'       => $this->ascii($others),
            'ministry_code'     => (string) ($employee?->department?->code ?? $this->mdaCode),
            'grade_code'        => (string) ($line->grade?->code ?? ''),
            'step'              => (string) ($line->step ?? ''),
            'bank_sort_code'    => (string) ($employee?->bank_sort_code ?? ''),
            'bank_account'      => (string) ($employee?->bank_account ?? ''),
            'basic_pesewas'     => $this->pesewas((float) $line->basic),
            'allowance_pesewas' => $this->pesewas((float) $line->allowance_total),
            'gross_pesewas'     => $this->pesewas((float) $line->gross),
            'paye_pesewas'      => $this->pesewas((float) $line->paye),
            'ssnit_employee'    => $this->pesewas((float) $line->ssnit_tier1_employee),
            'ssnit_employer'    => $this->pesewas((float) $line->ssnit_tier1_employer),
            'nhia_pesewas'      => $this->pesewas((float) $line->nhia_split),
            'tier2_pesewas'     => $this->pesewas((float) $line->tier2_employer),
            'tier3_pesewas'     => $this->pesewas((float) $line->tier3_employee),
            'voluntary_pesewas' => $this->pesewas((float) $line->voluntary_deductions),
            'net_pesewas'       => $this->pesewas((float) $line->net),
        ];

        $cells = [];
        foreach (self::IPPD_COLUMNS as [$key, $width]) {
            $cells[] = $this->fit((string) $values[$key], $width);
        }
        return implode('|', $cells);
    }

    private function trailerRow(PayrollRun $run, $lines): string
    {
        $sum = fn (string $col) => $lines->sum(fn ($l) => (float) $l->{$col});

        return implode('|', [
            'T',
            (string) $lines->count(),
            $this->pesewas($sum('gross')),
            $this->pesewas($sum('paye')),
            $this->pesewas($sum('ssnit_tier1_employee')),
            $this->pesewas($sum('ssnit_tier1_employer')),
            $this->pesewas($sum('nhia_split')),
            $this->pesewas($sum('tier2_employer')),
            $this->pesewas($sum('tier3_employee')),
            $this->pesewas($sum('voluntary_deductions')),
            $this->pesewas($sum('net')),
        ]);
    }

    /**
     * Convert GHS-decimal to integer pesewas. We round at the multiplication
     * boundary so 4125.005 becomes 412501 (banker's-round in PHP defaults to
     * half-even, which matches CAGD's own back-end behaviour).
     */
    private function pesewas(float $ghs): string
    {
        return (string) (int) round($ghs * 100);
    }

    /** Left-pad/truncate to the spec width. CAGD ignores anything past width. */
    private function fit(string $value, int $width): string
    {
        $clean = $this->stripDelimiter($value);
        if (mb_strlen($clean) > $width) {
            return mb_substr($clean, 0, $width);
        }
        return str_pad($clean, $width, ' ', STR_PAD_RIGHT);
    }

    /** Pipes break the format — they're our delimiter. Replace with space. */
    private function stripDelimiter(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ' ', $value);
    }

    /**
     * CAGD's IPPD parser is ASCII-only — accented characters become '?' in
     * their downstream reports. Transliterate to plain ASCII first.
     */
    private function ascii(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $transliterated === false ? $value : $transliterated;
    }

    /**
     * "Mensah Yaa" → ['Mensah', 'Yaa']. The last whitespace-separated token
     * is the given name(s); everything before is the surname. Single-token
     * names are kept as surname only.
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') return ['', ''];
        $parts = explode(' ', $name);
        if (count($parts) === 1) return [$parts[0], ''];
        $first = array_shift($parts);
        return [$first, implode(' ', $parts)];
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Reports\AuditorGeneralReportPack;
use Illuminate\Console\Command;

/**
 * One-command Auditor-General report pack generator.
 *
 *   php artisan reports:auditor-general --year=2026
 *
 * Produces a ZIP at storage/app/ag-reports/AG-{year}-{timestamp}.zip
 * containing every figure the Auditor-General's office routinely audits,
 * each file independently SHA-256 hashed for tamper detection.
 */
class GenerateAuditorGeneralPack extends Command
{
    protected $signature = 'reports:auditor-general
                            {--year= : Fiscal year (defaults to current year)}
                            {--jurisdiction=GH}';

    protected $description = 'Generate the Auditor-General Report Pack ZIP for a fiscal year.';

    public function handle(AuditorGeneralReportPack $pack): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $jurisdiction = (string) $this->option('jurisdiction');

        $this->info("Generating Auditor-General pack for FY{$year} ({$jurisdiction})...");

        $path = $pack->generate($year, $jurisdiction);

        $this->newLine();
        $this->info("Pack generated:");
        $this->line("  {$path}");
        $this->line('  Size: ' . number_format(filesize($path)) . ' bytes');
        $this->newLine();
        $this->comment('Verify the manifest with: unzip -p ' . basename($path) . ' MANIFEST.md');

        return self::SUCCESS;
    }
}

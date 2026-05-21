<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PayrollRun;
use App\Services\Payroll\Ippd\IppdExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generate the Oracle IPPD2/IPPD3 upload file for a payroll run.
 *
 *   php artisan payroll:ippd-export 42
 *   php artisan payroll:ippd-export 42 --print
 *
 * The file is written to the configured disk; the operator then uploads it
 * to the CAGD IPPD portal (per-MDA URL). `--print` streams to STDOUT for
 * smoke-testing the layout against a sample run before any production cut.
 */
class IppdExportCommand extends Command
{
    protected $signature = 'payroll:ippd-export
                            {run : Payroll run ID}
                            {--print : Stream the export to STDOUT instead of writing to disk}';

    protected $description = 'Build the Oracle IPPD2/IPPD3 CSV upload for a payroll run.';

    public function handle(IppdExporter $exporter): int
    {
        $runId = (int) $this->argument('run');
        $run = PayrollRun::find($runId);
        if (! $run) {
            $this->error("Payroll run #{$runId} not found.");
            return self::FAILURE;
        }

        if ($this->option('print')) {
            $this->output->writeln($exporter->preview($run));
            return self::SUCCESS;
        }

        $path = $exporter->build($run);
        $abs  = Storage::disk(config('payroll.ippd.output_disk', 'local'))->path($path);

        $this->info('IPPD export written:');
        $this->line("  storage path : {$path}");
        $this->line("  absolute     : {$abs}");
        $this->newLine();
        $this->comment('Upload this file to the CAGD IPPD portal for your MDA.');
        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PayrollRun;
use App\Services\Payroll\Gifmis\GifmisJournalExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generate the GIFMIS journal voucher CSV for a payroll run.
 *
 *   php artisan payroll:gifmis-export 42
 *   php artisan payroll:gifmis-export 42 --print
 *
 * Without `--print` the file is written to the configured disk and the
 * resolved storage path is echoed. With `--print` it streams to STDOUT for
 * smoke-testing the JV layout against a sample run before any cut-over.
 *
 * If the journal doesn't balance (a calculator residual), the exporter
 * throws — the command catches and prints the residual + exits FAILURE so
 * the cron alert fires.
 */
class GifmisExportCommand extends Command
{
    protected $signature = 'payroll:gifmis-export
                            {run : Payroll run ID}
                            {--print : Stream the JV to STDOUT instead of writing to disk}';

    protected $description = 'Build the GIFMIS journal-voucher CSV for a payroll run.';

    public function handle(GifmisJournalExporter $exporter): int
    {
        $runId = (int) $this->argument('run');
        $run = PayrollRun::find($runId);
        if (! $run) {
            $this->error("Payroll run #{$runId} not found.");
            return self::FAILURE;
        }

        try {
            if ($this->option('print')) {
                $this->output->writeln($exporter->preview($run));
                return self::SUCCESS;
            }

            $path = $exporter->build($run);
            $abs  = Storage::disk(config('payroll.gifmis.output_disk', 'local'))->path($path);

            $this->info('GIFMIS journal written:');
            $this->line("  storage path : {$path}");
            $this->line("  absolute     : {$abs}");
            $this->newLine();
            $this->comment('Upload this file to the GIFMIS sub-ledger journal import for your MDA.');
            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}

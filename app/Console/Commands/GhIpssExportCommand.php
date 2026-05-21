<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PayrollRun;
use App\Services\Disbursement\GhIpssBatchFileBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generate the GhIPSS bulk-payment CSV for a payroll run.
 *
 *   php artisan disbursement:ghipss-export 42
 *   php artisan disbursement:ghipss-export 42 --print
 *
 * Without `--print` the file is written to the configured disk and the
 * resolved storage path is echoed (operator then downloads or syncs it to
 * the sponsor-bank portal). With `--print` it streams to STDOUT, useful for
 * smoke-testing the layout against a sample run before any cut-over.
 */
class GhIpssExportCommand extends Command
{
    protected $signature = 'disbursement:ghipss-export
                            {run : Payroll run ID}
                            {--print : Stream the CSV to STDOUT instead of writing to disk}';

    protected $description = 'Build the GhIPSS bulk-payment CSV file for a payroll run.';

    public function handle(GhIpssBatchFileBuilder $builder): int
    {
        $runId = (int) $this->argument('run');
        $run = PayrollRun::find($runId);
        if (! $run) {
            $this->error("Payroll run #{$runId} not found.");
            return self::FAILURE;
        }

        if ($this->option('print')) {
            $this->output->writeln($builder->preview($run));
            return self::SUCCESS;
        }

        $path = $builder->build($run);
        $abs  = Storage::disk(config('disbursement.providers.ghipss_ach.output_disk', 'local'))->path($path);

        $this->info("GhIPSS batch file written:");
        $this->line("  storage path : {$path}");
        $this->line("  absolute     : {$abs}");
        $this->newLine();
        $this->comment("Upload this file to the sponsor bank's bulk-payment portal.");
        return self::SUCCESS;
    }
}

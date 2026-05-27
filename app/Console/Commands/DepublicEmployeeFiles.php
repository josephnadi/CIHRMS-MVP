<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Move avatars + employee-documents off the world-readable `public` disk
 * and onto the default `local` disk. Same relative path on the destination
 * (e.g. `public://avatars/abc.jpg` → `local://avatars/abc.jpg`).
 *
 * Why: anything served from `storage/app/public/` is reachable via the
 * `public/storage` symlink at `/storage/...` with no auth check. Employee
 * avatars are personal data; employee-documents (CVs, contracts, ID copies)
 * are highly sensitive under DPA 2012. Both should live on a non-public
 * disk and be served through an auth-checked Laravel route.
 *
 * This command is the **migration** half of that change. The other half —
 * updating EmployeeService / AvatarController / DocumentController to read
 * from the `local` disk + stream via authorised routes — is application
 * code, separate.
 *
 * Idempotent. Re-running is safe: files already at the destination are
 * skipped (not overwritten).
 *
 *   php artisan storage:depublic-employee-files --dry-run
 *   php artisan storage:depublic-employee-files
 *   php artisan storage:depublic-employee-files --include=avatars
 */
class DepublicEmployeeFiles extends Command
{
    protected $signature = 'storage:depublic-employee-files
                            {--dry-run               : Report what would move without writing}
                            {--include=avatars,employee-documents : Comma-separated list of directories to migrate}
                            {--from=public           : Source disk}
                            {--to=local              : Destination disk}';

    protected $description = 'Move avatars + employee-documents from the public disk to a non-public disk.';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $fromDisk  = (string) $this->option('from');
        $toDisk    = (string) $this->option('to');
        $directories = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('include')))));

        if ($directories === []) {
            $this->error('No directories to migrate. Pass --include=<comma-separated>.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Mode: %s | from=%s → to=%s | dirs=%s',
            $dryRun ? 'DRY RUN' : 'WRITE',
            $fromDisk,
            $toDisk,
            implode(', ', $directories),
        ));

        $totals = ['moved' => 0, 'skipped_exists' => 0, 'failed' => 0, 'empty_dirs' => 0];

        foreach ($directories as $dir) {
            $files = Storage::disk($fromDisk)->allFiles($dir);
            if (count($files) === 0) {
                $this->line("  [{$dir}] no files on {$fromDisk} disk");
                $totals['empty_dirs']++;
                continue;
            }

            $this->line("  [{$dir}] " . count($files) . ' file(s) found on ' . $fromDisk);

            foreach ($files as $path) {
                // Skip if destination already has this file — don't silently
                // overwrite something a previous run (or external process)
                // wrote to the destination.
                if (Storage::disk($toDisk)->exists($path)) {
                    $this->line("    SKIP  {$path} (already on {$toDisk})");
                    $totals['skipped_exists']++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("    MOVE  {$path}");
                    $totals['moved']++;
                    continue;
                }

                // Stream-aware copy so large CVs don't blow PHP memory.
                try {
                    $src = Storage::disk($fromDisk)->readStream($path);
                    if ($src === false || $src === null) {
                        throw new \RuntimeException("readStream returned null for {$path}");
                    }
                    Storage::disk($toDisk)->put($path, $src);
                    if (is_resource($src)) {
                        fclose($src);
                    }

                    // Belt-and-braces: verify destination wrote successfully
                    // before deleting the source.
                    if (! Storage::disk($toDisk)->exists($path)) {
                        throw new \RuntimeException("destination write verification failed for {$path}");
                    }

                    Storage::disk($fromDisk)->delete($path);
                    $this->info("    OK    {$path}");
                    $totals['moved']++;
                } catch (\Throwable $e) {
                    $this->error("    FAIL  {$path} — " . $e->getMessage());
                    $totals['failed']++;
                }
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Summary: moved=%d skipped=%d failed=%d empty_dirs=%d',
            $totals['moved'],
            $totals['skipped_exists'],
            $totals['failed'],
            $totals['empty_dirs'],
        ));

        if ($dryRun) {
            $this->warn('Dry-run — re-run without --dry-run to apply.');
        }

        if ($totals['failed'] > 0) {
            $this->error('One or more files failed to move. Review the FAIL lines above and re-run.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the SHA-256 hash chain over `audit_logs` rows that pre-date the
 * `2026_05_25_000007_add_tamper_evident_audit_columns` migration.
 *
 * Walks every row missing `chain_position` (or `row_hash`) in chronological
 * order (oldest `created_at`, then oldest `id`) and assigns them sequential
 * chain positions, computing the link hash off the previous row's hash.
 *
 * Designed to be idempotent — running it a second time on an intact chain
 * is a no-op. Running it while concurrent traffic writes new audit rows is
 * also safe: the new rows pick up the chain naturally once the backfill
 * completes, because `WriteAuditLog` reads the latest `chain_position`.
 *
 *   php artisan audit:backfill-chain
 *   php artisan audit:backfill-chain --dry-run
 */
class BackfillAuditChain extends Command
{
    protected $signature = 'audit:backfill-chain
                            {--dry-run : Report what would change without writing}
                            {--chunk=500 : How many rows to walk per batch}';

    protected $description = 'Backfill chain_position + previous_hash + row_hash on legacy audit rows.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(50, (int) $this->option('chunk'));

        // Anchor on the chain's existing tail — if some rows already have hash
        // values (from WriteAuditLog runs after the migration), we extend from
        // the highest chain_position rather than starting from zero.
        $tail = AuditLog::query()
            ->whereNotNull('chain_position')
            ->orderByDesc('chain_position')
            ->first();

        $position     = ($tail?->chain_position ?? 0);
        $previousHash = $tail?->row_hash; // null if there's no chain yet

        $pending = AuditLog::query()
            ->where(fn ($q) => $q->whereNull('chain_position')->orWhereNull('row_hash'))
            ->orderBy('created_at')
            ->orderBy('id');

        $total = (clone $pending)->count();
        if ($total === 0) {
            $this->info('Audit chain has no gaps — nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d row(s) starting at chain_position %d (previous_hash=%s).',
            $dryRun ? 'Would backfill' : 'Backfilling',
            $total,
            $position + 1,
            $previousHash ? substr($previousHash, 0, 12).'…' : '<genesis>',
        ));

        $processed = 0;

        $pending->chunkById($chunk, function ($rows) use (&$position, &$previousHash, &$processed, $dryRun) {
            foreach ($rows as $row) {
                $position++;

                $row->chain_position = $position;
                $row->previous_hash  = $previousHash;
                $row->row_hash       = null; // recomputed below
                $row->row_hash       = $row->computeHash();

                if (! $dryRun) {
                    DB::transaction(fn () => $row->saveQuietly());
                }

                $previousHash = $row->row_hash;
                $processed++;
            }
        });

        $this->info(sprintf(
            '%s %d row(s). Tail position is now %d.',
            $dryRun ? 'Would have processed' : 'Backfilled',
            $processed,
            $position,
        ));

        return self::SUCCESS;
    }
}

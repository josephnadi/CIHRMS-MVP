<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resets every Postgres serial/identity sequence to MAX(id)+1 of its table.
 *
 * After importing data into Postgres from another driver (SQLite, MySQL),
 * the underlying sequences are still at 1 even though the imported rows
 * have ids up to N. The next INSERT then collides on the primary key. This
 * command walks every `*_id_seq` and bumps it to the right value.
 *
 *   php artisan db:reset-sequences
 *   php artisan db:reset-sequences --dry-run
 *
 * No-op on SQLite and MySQL — they auto-track the next id from MAX(id).
 */
class ResetSequences extends Command
{
    protected $signature = 'db:reset-sequences {--dry-run : Report what would change without applying}';

    protected $description = 'Postgres only — reset every serial sequence to MAX(id)+1 of its owning table.';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->info("Driver is {$driver}; sequence reset is a Postgres-only operation. Skipping.");
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Pull every sequence owned by a column in the current schema. The
        // pg_get_serial_sequence() function returns the sequence name for a
        // given table+column, which avoids the brittle string-mangling of
        // "{$table}_{$column}_seq" — works for both bigserial and identity
        // columns added in Postgres 10+.
        $rows = DB::select(<<<'SQL'
            SELECT t.table_name AS table_name,
                   c.column_name AS column_name,
                   pg_get_serial_sequence(quote_ident(t.table_name), c.column_name) AS sequence_name
            FROM information_schema.tables t
            JOIN information_schema.columns c ON c.table_name = t.table_name
            WHERE t.table_schema = 'public'
              AND t.table_type = 'BASE TABLE'
              AND c.column_default LIKE 'nextval%'
            ORDER BY t.table_name;
        SQL);

        if (empty($rows)) {
            $this->info('No sequences found to reset.');
            return self::SUCCESS;
        }

        $reset = 0;
        foreach ($rows as $row) {
            $table  = $row->table_name;
            $column = $row->column_name;
            $seq    = $row->sequence_name;
            if (! $seq) continue;

            $max = (int) (DB::table($table)->max($column) ?? 0);
            $target = max(1, $max);

            $this->line("  {$seq} → setval({$target}) [table={$table}, column={$column}]");

            if (! $dryRun) {
                // is_called=true means the NEXT call returns target+1, which is
                // what we want for an existing table; is_called=false would
                // make the next call return target, which would collide with
                // the row already holding that id.
                DB::statement('SELECT setval(?, ?, true)', [$seq, $target]);
            }
            $reset++;
        }

        $this->info(sprintf('%s %d sequence(s).', $dryRun ? 'Would reset' : 'Reset', $reset));
        return self::SUCCESS;
    }
}

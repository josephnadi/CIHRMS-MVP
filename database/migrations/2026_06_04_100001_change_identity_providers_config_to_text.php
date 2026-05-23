<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: identity_providers.config was declared as JSON in the original sso_tables
 * migration, but the SsoIdentityProvider model casts it as 'encrypted:array'.
 * The cast produces an encrypted base64 blob (not valid JSON), which Postgres
 * rejects with SQLSTATE[22P02] on INSERT. SQLite was forgiving.
 *
 * Change the column type to TEXT so it accepts ciphertext. Application-level
 * JSON shape is preserved by the model's cast on decrypt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Postgres can't cast json → text directly without a USING clause.
            DB::statement('ALTER TABLE identity_providers ALTER COLUMN config TYPE TEXT USING config::text');
        } else {
            // SQLite has no real ALTER COLUMN; the column already accepts arbitrary
            // strings, so this is a no-op. MySQL/MariaDB would also accept this:
            if (Schema::hasColumn('identity_providers', 'config')) {
                // Best-effort for non-pgsql drivers: rely on Schema::table when supported.
                Schema::table('identity_providers', function ($table) {
                    // Doctrine DBAL may not be installed; if not, skip silently —
                    // SQLite accepts the encrypted blob already.
                    if (class_exists(\Doctrine\DBAL\Driver\PDO\Driver::class)) {
                        $table->text('config')->change();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Intentional no-op: reverting to json would re-break Postgres.
    }
};

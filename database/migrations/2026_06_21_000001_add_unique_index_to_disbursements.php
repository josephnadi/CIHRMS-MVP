<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fix: a payroll run could materialise two disbursements for the same
 * payroll line. `materialise()` guarded with a check-then-create (exists() then
 * create()), which is a TOCTOU race — two concurrent dispatch clicks both pass
 * the exists() check and both insert. This adds the DB-level guarantee.
 *
 * Partial (`WHERE deleted_at IS NULL`) because the table soft-deletes: a future
 * soft-delete + re-materialise must not be blocked by the tombstone row.
 * Settlement / ad-hoc disbursements carry NULL run+line ids; NULLs are distinct
 * in a unique index on both Postgres and SQLite, so they never collide here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Portable across the Postgres (prod/CI) and SQLite (test) drivers this
        // app targets — both accept a partial unique index with this syntax.
        DB::statement(
            'CREATE UNIQUE INDEX disbursements_run_line_unique '
            .'ON disbursements (payroll_run_id, payroll_line_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('disbursements', function ($table) {
            $table->dropIndex('disbursements_run_line_unique');
        });
    }
};

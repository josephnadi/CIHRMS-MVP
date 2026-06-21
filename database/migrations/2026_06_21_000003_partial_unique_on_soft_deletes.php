<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fix: these tables soft-delete, yet their business-identifier unique
 * indexes were plain — so once a row was soft-deleted its key (employee number,
 * email, department/vendor/product code, asset tag, …) stayed permanently
 * reserved by the tombstone, blocking a legitimate re-create (re-hire,
 * re-onboard, re-issue an asset tag). Rebuild each as a PARTIAL unique index
 * scoped to live rows (WHERE deleted_at IS NULL) so uniqueness still holds among
 * active records but freed keys can be reused.
 *
 * Scope is deliberately the user/import-supplied identifiers; sequence-allocated
 * references (invoice/payroll/case numbers) are monotonic and never reused, so
 * they keep their plain unique index.
 *
 * Portable across Postgres (prod/CI) and SQLite (test) — both accept the partial
 * index syntax; Laravel rebuilds the SQLite table for the dropUnique.
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string}> [table, column] */
    private array $targets = [
        ['employees',         'employee_no'],
        ['users',             'email'],
        ['departments',       'code'],
        ['departments',       'name'],
        ['vendors',           'code'],
        ['customers',         'code'],
        ['members',           'member_no'],
        ['assets',            'asset_tag'],
        ['loan_products',     'code'],
        ['fee_products',      'code'],
        ['benefit_plans',     'code'],
        ['grades',            'code'],
        ['positions',         'code'],
        ['biometric_devices', 'code'],
        ['shifts',            'code'],
        ['courses',           'slug'],
        ['policies',          'slug'],
        ['identity_providers', 'slug'],
        ['gl_accounts',       'code'],
        ['bank_statements',   'file_hash'],
        ['payment_intents',   'paystack_reference'],
        ['documents',         'ref_no'],
    ];

    public function up(): void
    {
        foreach ($this->targets as [$table, $column]) {
            $name = "{$table}_{$column}_unique";

            Schema::table($table, function (Blueprint $t) use ($name) {
                $t->dropUnique($name);
            });

            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$column}) WHERE deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        foreach ($this->targets as [$table, $column]) {
            $name = "{$table}_{$column}_unique";

            DB::statement("DROP INDEX {$name}");

            Schema::table($table, function (Blueprint $t) use ($column, $name) {
                $t->unique($column, $name);
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident audit log — adds a SHA-256 hash chain so any post-hoc
 * mutation of an `audit_logs` row breaks the chain and is detectable
 * via `php artisan audit:verify-chain`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('chain_position')->nullable()->after('id');
            $table->char('previous_hash', 64)->nullable()->after('chain_position');
            $table->char('row_hash', 64)->nullable()->after('previous_hash');

            $table->index('chain_position');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['chain_position']);
            $table->dropColumn(['chain_position', 'previous_hash', 'row_hash']);
        });
    }
};

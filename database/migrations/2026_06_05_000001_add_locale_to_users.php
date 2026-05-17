<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user locale preference (Phase 4 / WS20).
 *
 * Resolved at every request by SetUserLocale middleware:
 *   1. ?locale=tw query param (overrides for the request)
 *   2. users.locale column
 *   3. Accept-Language header (best-match against supported set)
 *   4. fallback to AppLocale::default()
 *
 * Notifications, payslip PDFs, and SMS bodies all read this column so
 * communications go out in the recipient's chosen language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->default('en')->after('email');
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['locale']);
            $table->dropColumn('locale');
        });
    }
};

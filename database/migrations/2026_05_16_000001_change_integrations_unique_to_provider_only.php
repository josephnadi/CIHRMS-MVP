<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 10 — multi-capability providers (MS Graph serves files+spreadsheet+calendar
 * with a single OAuth grant). One Integration row per provider, with `capability`
 * indicating the *primary* capability for UI grouping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropUnique(['provider', 'capability']);
            $table->unique('provider');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropUnique(['provider']);
            $table->unique(['provider', 'capability']);
        });
    }
};

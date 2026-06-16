<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source_purpose', 50)->default('')->after('source_id');
            $table->unique(
                ['source_type', 'source_id', 'source_purpose'],
                'journal_entries_source_idem_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_source_idem_unique');
            $table->dropColumn('source_purpose');
        });
    }
};

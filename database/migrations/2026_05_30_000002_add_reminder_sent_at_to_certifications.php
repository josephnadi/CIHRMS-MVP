<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certifications', function (Blueprint $table) {
            if (! Schema::hasColumn('certifications', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('verification_url');
                $table->index('reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('certifications', function (Blueprint $table) {
            if (Schema::hasColumn('certifications', 'reminder_sent_at')) {
                $table->dropIndex(['reminder_sent_at']);
                $table->dropColumn('reminder_sent_at');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('external_crm_id')->nullable()->after('manager_id');
            $table->index('external_crm_id');
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->string('esign_provider')->nullable()->after('status');
            $table->string('esign_envelope_id')->nullable();
            $table->string('esign_status')->nullable();          // sent, viewed, completed, declined, voided
            $table->timestamp('esign_sent_at')->nullable();
            $table->timestamp('esign_completed_at')->nullable();
            $table->index('esign_envelope_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['external_crm_id']);
            $table->dropColumn('external_crm_id');
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->dropIndex(['esign_envelope_id']);
            $table->dropColumn(['esign_provider', 'esign_envelope_id', 'esign_status', 'esign_sent_at', 'esign_completed_at']);
        });
    }
};

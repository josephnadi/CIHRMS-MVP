<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_payments', function (Blueprint $table) {
            $table->string('external_ref', 100)->nullable()->after('narration');
            $table->index('external_ref');
        });
    }

    public function down(): void
    {
        Schema::table('ap_payments', function (Blueprint $table) {
            $table->dropIndex(['external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};

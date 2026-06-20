<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('tier3_rate', 6, 4)->default(0)->after('tier2_trustee_id');
            $table->foreignId('tier3_trustee_id')->nullable()->after('tier3_rate')
                ->constrained('pension_trustees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tier3_trustee_id');
            $table->dropColumn('tier3_rate');
        });
    }
};

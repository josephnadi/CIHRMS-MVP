<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->foreignId('requirement_id')->nullable()->after('employee_id')
                ->constrained('compliance_requirements')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->after('enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requirement_id');
            $table->dropColumn('due_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service "My Documents": an employee may manage (rename / replace /
 * delete) the documents THEY uploaded, while documents HR placed on their file
 * stay download-only. We need to know who uploaded each row to draw that line.
 * Existing rows get NULL (treated as HR/legacy → not employee-managed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('employee_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropColumn('uploaded_by');
        });
    }
};

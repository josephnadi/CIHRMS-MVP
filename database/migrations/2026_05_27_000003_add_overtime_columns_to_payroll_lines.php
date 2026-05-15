<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_lines', 'overtime_hours')) {
            Schema::table('payroll_lines', function (Blueprint $table) {
                $table->decimal('overtime_hours', 6, 2)->default(0);
                $table->decimal('overtime_pay', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn(['overtime_hours', 'overtime_pay']);
        });
    }
};

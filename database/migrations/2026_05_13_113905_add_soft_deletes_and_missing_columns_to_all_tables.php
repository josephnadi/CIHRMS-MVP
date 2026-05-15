<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SoftDeletes on all core tables
        foreach (['users', 'departments', 'employees', 'leave_requests', 'tickets', 'complaints', 'job_postings', 'applicants', 'payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->softDeletes();
            });
        }

        // approved_by on leave_requests (set when a manager approves/rejects)
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('employee_id');
        });

        // assigned_to + resolved_at on tickets
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete()->after('employee_id');
            $table->timestamp('resolved_at')->nullable()->after('due_at');
        });

        // processed_by on payments
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['processed_by']);
            $table->dropColumn('processed_by');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropColumn(['assigned_to', 'resolved_at']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn('approved_by');
        });

        foreach (['users', 'departments', 'employees', 'leave_requests', 'tickets', 'complaints', 'job_postings', 'applicants', 'payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};

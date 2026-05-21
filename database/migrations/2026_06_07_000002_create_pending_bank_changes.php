<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending bank-account changes — two-factor confirmation queue.
 *
 * Payroll-redirection fraud (an attacker editing an employee's bank account
 * just before payroll runs) is the #1 internal-fraud scenario in HR systems.
 * We mitigate it by holding the change in this table, SMSing a 6-digit code
 * to the employee's registered phone, and requiring them to confirm via USSD
 * before the change is applied to `employees.bank_account`.
 *
 *   pending  → confirmed → applied (terminal)
 *   pending  → rejected  (terminal)
 *   pending  → expired   (terminal, via cron)
 *
 * The OLD bank values are snapshotted here too so the audit log shows
 * exactly what changed when the application was approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_bank_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // Old values (snapshot for audit), new values (what to apply on confirm)
            $table->string('old_bank_name')->nullable();
            $table->string('old_bank_account')->nullable();
            $table->string('old_bank_sort_code')->nullable();
            $table->string('new_bank_name')->nullable();
            $table->string('new_bank_account');
            $table->string('new_bank_sort_code')->nullable();

            // 6-digit confirmation code (hashed at rest), expiry, failed-attempt count
            $table->string('code_hash');
            $table->timestamp('code_expires_at');
            $table->unsignedTinyInteger('failed_attempts')->default(0);

            $table->string('status', 16)->default('pending'); // pending|confirmed|applied|rejected|expired
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 120)->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_bank_changes');
    }
};

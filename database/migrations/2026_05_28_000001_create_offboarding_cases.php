<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Off-boarding case aggregate. One row per departing employee.
 *
 * Lifecycle: draft → in_progress → awaiting_settlement → settled → completed
 * Reference format: OFF-YYYY-NNNNN, generated at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('exit_type', 32);
            $table->string('status', 32)->default('draft');
            $table->date('notice_received_on');
            $table->date('last_working_day');
            $table->date('effective_termination_date');     // usually = last_working_day, can differ
            $table->boolean('rehire_eligible')->default(true);
            $table->text('reason')->nullable();
            $table->text('exit_interview_summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'effective_termination_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_cases');
    }
};

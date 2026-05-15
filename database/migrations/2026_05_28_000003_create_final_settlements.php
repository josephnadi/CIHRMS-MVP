<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The computed final-settlement snapshot for a closing case.
 *
 * Snapshots ALL inputs (basic salary, years of service, leave balance,
 * Act 651 multipliers) at calculation time so a re-calc after a rule change
 * doesn't silently rewrite history. Approval freezes the row; payment links
 * to a payroll line for the off-cycle disbursement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_case_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('calculated'); // calculated|approved|paid|cancelled

            // Snapshot of computation inputs
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('years_of_service', 6, 2);          // 4.50 means 4 years 6 months
            $table->decimal('accrued_leave_days', 5, 2);
            $table->decimal('working_days_per_month', 5, 2)->default(22);

            // Earnings
            $table->decimal('gratuity', 14, 2)->default(0);
            $table->decimal('severance', 14, 2)->default(0);     // Act 651 §31 redundancy
            $table->decimal('leave_encashment', 14, 2)->default(0);
            $table->decimal('prorated_13th_month', 14, 2)->default(0);
            $table->decimal('ex_gratia', 14, 2)->default(0);     // discretionary additions
            $table->decimal('gross_settlement', 14, 2)->default(0);

            // Deductions
            $table->decimal('outstanding_loans', 14, 2)->default(0);
            $table->decimal('garnishments', 14, 2)->default(0);
            $table->decimal('other_deductions', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);

            // Statutory on the settlement
            $table->decimal('paye_on_settlement', 14, 2)->default(0);

            // Net payable
            $table->decimal('net_payable', 14, 2)->default(0);

            // Workflow
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('payroll_line_id')->nullable()->constrained('payroll_lines')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->json('breakdown')->nullable();              // full audit-friendly snapshot
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['offboarding_case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_settlements');
    }
};

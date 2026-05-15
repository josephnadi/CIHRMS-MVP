<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scheduled installment. Created in bulk at disbursement;
 * `payroll_run_id` is filled in when a run claims the row, and `status`
 * flips to 'paid' when the run is approved.
 *
 * Keeping the schedule in a separate table (rather than recomputing
 * on every payroll run) makes deferrals, waivers, and missed-payment
 * recovery straightforward and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');               // 1..term_months
            $table->date('due_period');                                    // YYYY-MM-01
            $table->decimal('scheduled_amount', 12, 2);
            $table->decimal('principal_portion', 12, 2);
            $table->decimal('interest_portion', 12, 2);
            $table->decimal('balance_after', 14, 2);                       // principal remaining after this installment
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status', 16)->default('scheduled');           // scheduled|paid|missed|deferred|waived
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->foreignId('payroll_line_id')->nullable()->constrained('payroll_lines')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['loan_account_id', 'installment_no'], 'loan_repayment_installment_unique');
            $table->index(['due_period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
    }
};

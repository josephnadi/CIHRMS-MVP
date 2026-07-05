<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back-pay (arrears) run: pays the retroactive difference owed when a salary
 * revision takes effect from a month already paid at the old rate. Mirrors the
 * payroll-run lifecycle (draft → approved → paid) but carries only the deltas —
 * arrears net, back-PAYE, and the statutory contribution increases — so the
 * approval GL entry balances exactly like a payroll accrual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('back_pay_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('salary_revision_id')->constrained()->restrictOnDelete();
            $table->date('effective_from'); // copied from the revision
            $table->string('status', 16)->default('draft'); // draft | approved | paid | reversed

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('notes')->nullable();

            // Cached totals (sum of the lines).
            $table->unsignedSmallInteger('employees_count')->default(0);
            $table->decimal('gross_total', 14, 2)->default(0);          // Σ (new gross − old gross)
            $table->decimal('arrears_net_total', 14, 2)->default(0);    // owed to staff
            $table->decimal('back_paye_total', 14, 2)->default(0);      // owed to GRA
            $table->decimal('ssnit_employee_total', 14, 2)->default(0);
            $table->decimal('ssnit_employer_total', 14, 2)->default(0);
            $table->decimal('tier2_employer_total', 14, 2)->default(0);
            $table->decimal('tier3_employee_total', 14, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('back_pay_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('back_pay_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            $table->decimal('gross', 12, 2)->default(0);           // new gross − old gross
            $table->decimal('arrears_net', 12, 2)->default(0);
            $table->decimal('back_paye', 12, 2)->default(0);
            $table->decimal('ssnit_employee', 12, 2)->default(0);
            $table->decimal('ssnit_employer', 12, 2)->default(0);
            $table->decimal('tier2_employer', 12, 2)->default(0);
            $table->decimal('tier3_employee', 12, 2)->default(0);

            $table->json('breakdown')->nullable(); // per-month deltas for the audit trail

            $table->timestamps();

            $table->index('back_pay_run_id');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('back_pay_lines');
        Schema::dropIfExists('back_pay_runs');
    }
};

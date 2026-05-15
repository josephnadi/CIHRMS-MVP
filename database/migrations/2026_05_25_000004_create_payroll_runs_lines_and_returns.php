<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Period-based payroll-run aggregate. Replaces the ad-hoc per-employee Payment
 * generation with a locked, reversible, dual-approval run that produces
 * statutory returns as a side-effect of approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('draft');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete(); // null = whole org
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reason')->nullable();

            // Cached totals (recomputed on each calculate())
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('paye_total', 14, 2)->default(0);
            $table->decimal('ssnit_tier1_employee_total', 14, 2)->default(0);
            $table->decimal('ssnit_tier1_employer_total', 14, 2)->default(0);
            $table->decimal('nhia_total', 14, 2)->default(0);
            $table->decimal('tier2_employer_total', 14, 2)->default(0);
            $table->decimal('tier3_total', 14, 2)->default(0);
            $table->decimal('voluntary_deductions_total', 14, 2)->default(0);
            $table->unsignedSmallInteger('lines_count')->default(0);
            $table->unsignedSmallInteger('skipped_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['period_year', 'period_month', 'department_id'], 'payroll_runs_period_unique');
            $table->index('status');
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('step')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete(); // generated post-approval

            $table->decimal('basic', 12, 2);
            $table->decimal('allowance_total', 12, 2)->default(0);
            $table->decimal('gross', 12, 2);
            $table->decimal('ssnit_base', 12, 2); // capped at MAX_INSURABLE_EARNINGS
            $table->decimal('ssnit_tier1_employee', 12, 2);
            $table->decimal('ssnit_tier1_employer', 12, 2);
            $table->decimal('nhia_split', 12, 2)->default(0); // portion of Tier-1 employer routed to NHIA
            $table->decimal('tier2_employer', 12, 2);
            $table->decimal('tier3_employee', 12, 2)->default(0);
            $table->decimal('paye', 12, 2);
            $table->decimal('voluntary_deductions', 12, 2)->default(0);
            $table->decimal('net', 12, 2);

            $table->json('breakdown')->nullable(); // full calculator snapshot for the audit trail
            $table->string('status', 16)->default('calculated'); // calculated | skipped | paid | reversed
            $table->string('skip_reason')->nullable();

            $table->timestamps();

            $table->index(['payroll_run_id', 'status']);
            $table->index('employee_id');
        });

        Schema::create('statutory_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->foreignId('trustee_id')->nullable()->constrained('pension_trustees')->nullOnDelete();
            $table->string('file_path');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('record_count')->default(0);
            $table->timestamp('generated_at');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submission_reference', 128)->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_returns');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
    }
};

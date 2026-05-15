<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan account = a single loan agreement against an employee.
 *
 * Snapshots the product terms at booking time (`booked_interest_rate`,
 * `booked_amortization_method`) so subsequent product edits never
 * silently re-price an active loan.
 *
 * Outstanding balance is decremented as payroll runs post repayments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();                    // LOAN-2026-00001
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('loan_products')->nullOnDelete();
            $table->string('status', 24)->default('draft');

            // Terms snapshotted from the product at booking
            $table->decimal('principal', 14, 2);
            $table->unsignedSmallInteger('term_months');
            $table->decimal('booked_interest_rate', 6, 4)->default(0);
            $table->string('booked_amortization_method', 32)->default('reducing_balance');
            $table->decimal('monthly_installment', 12, 2);                 // computed at booking
            $table->decimal('total_interest', 14, 2)->default(0);
            $table->decimal('total_repayable', 14, 2);

            // Live state
            $table->decimal('disbursed_amount', 14, 2)->default(0);
            $table->decimal('outstanding_balance', 14, 2)->default(0);    // principal portion remaining
            $table->unsignedSmallInteger('installments_paid')->default(0);

            // Workflow
            $table->text('purpose')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->date('first_repayment_period')->nullable();           // YYYY-MM-01
            $table->date('expected_end_period')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'first_repayment_period']);
        });

        Schema::create('loan_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->boolean('has_consented')->default(false);
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
            $table->unique(['loan_account_id', 'employee_id'], 'loan_guarantor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_guarantors');
        Schema::dropIfExists('loan_accounts');
    }
};

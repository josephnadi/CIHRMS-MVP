<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan product catalogue. Each row is a *template* defining the limits,
 * interest rate, and amortization method that loan applications book against.
 *
 * Effective-dated via `effective_from` so rate / cap changes don't retroactively
 * alter existing LoanAccounts (those snapshot the product at booking time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type', 32);                         // matches LoanProductType enum
            $table->decimal('min_amount', 12, 2)->default(0);
            $table->decimal('max_amount', 14, 2);
            $table->unsignedSmallInteger('min_term_months')->default(1);
            $table->unsignedSmallInteger('max_term_months');
            $table->decimal('annual_interest_rate', 6, 4)->default(0); // 0.0 = interest-free
            $table->string('amortization_method', 32)->default('reducing_balance');
            $table->decimal('max_dti_ratio', 5, 4)->nullable();        // debt-to-income cap, e.g. 0.4000 = 40%
            $table->boolean('requires_guarantor')->default(false);
            $table->boolean('requires_collateral')->default(false);
            $table->unsignedTinyInteger('approvals_required')->default(2); // dual-control by default
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};

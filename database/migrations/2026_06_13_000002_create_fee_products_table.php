<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fee_products` — the catalog of billable items the institute can charge
 * its members for (Annual Member Dues, Graduation Fee, Exam Fee, etc.).
 * A `FeeProduct` is reusable across periods — the actual "member X owes
 * Annual Dues for 2026" row lives on `fee_assignments`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fee_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('billing_cycle', 20);                   // BillingCycle enum value
            // Which MemberClass values this product applies to. Empty array
            // = applies to none; null/missing = applies to all classes.
            $table->json('applies_to_classes')->nullable();

            // GL income account credited when an invoice for this product
            // is posted. Required so the accrual JE has a destination.
            $table->foreignId('gl_income_account_id')
                ->constrained('gl_accounts');

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_products');
    }
};

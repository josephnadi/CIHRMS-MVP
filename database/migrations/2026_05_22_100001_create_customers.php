<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer master data for accounts receivable. Mirrors vendors on the AR
 * side. Each customer optionally pre-sets a default income GL (snapshotted
 * onto invoice lines as a hint), a default AR asset GL (snapshotted onto
 * invoices at creation; falls back to GL code 1200), and a preferred org
 * bank account for incoming receipts. SoftDeletes — archive guard in
 * CustomerService refuses archive if any non-cancelled/non-written-off AR
 * invoices reference the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('tax_id', 50)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('default_income_gl_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->foreignId('default_ar_gl_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->foreignId('default_bank_account_id')->nullable()->constrained('org_bank_accounts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

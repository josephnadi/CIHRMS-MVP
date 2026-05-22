<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP payment header. Records that the institute paid (or is about to pay) a
 * vendor from a specific org bank account. When the payment is recorded,
 * ApPaymentService auto-posts a payment JournalEntry:
 *   Dr AP GL (per allocated invoice's ap_gl_account_id, for allocated_amount)
 *   Cr Bank GL (the org_bank_account's linked gl_account_id, for the total)
 * `disbursement_id` is set later when an operator triggers "Send via GhIPSS".
 * `journal_entry_id` is set at posting time and never modified afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('GHS');
            $table->foreignId('org_bank_account_id')->constrained('org_bank_accounts')->restrictOnDelete();
            $table->string('narration', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('disbursement_id')->nullable()->constrained('disbursements')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_payments');
    }
};

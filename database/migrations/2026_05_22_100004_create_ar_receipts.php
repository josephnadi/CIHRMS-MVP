<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR receipts (money received from customers). Each receipt allocates to
 * one or more AR invoices via ar_receipt_invoice_allocations. On record,
 * ArReceiptService posts:
 *   Dr Bank GL (the receiving org_bank_account's gl_account_id)
 *   Cr AR GL (per allocated invoice's ar_gl_account_id)
 *
 * `external_ref` carries the bank/MoMo transaction id; F4 (Paystack) will
 * populate it from webhook payloads. `voided_by` + `voided_at` are set by
 * ArReceiptService::void() which reverses the receipt JE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->date('receipt_date');
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('GHS');
            $table->foreignId('org_bank_account_id')->constrained('org_bank_accounts')->restrictOnDelete();
            $table->string('external_ref', 100)->nullable()->index();
            $table->string('narration', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('receipt_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_receipts');
    }
};

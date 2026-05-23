<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paystack payment intent. One row per "Send Payment Link" action. Links a
 * Paystack hosted-checkout transaction to a CIHRMS customer + AR invoice.
 *
 * Lifecycle:
 *   created → pending → success
 *                     → failed
 *                     → abandoned
 *                     → expired
 *
 * `paystack_reference` is the canonical key for webhook lookup (UNIQUE).
 * `ar_receipt_id` is set when the webhook posts the AR receipt, linking
 * the intent to its resulting receipt for audit. `ar_invoice_id` is
 * nullable to reserve forward-compat for customer-credit intents (not
 * used in F4 itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('ar_invoice_id')->nullable()->constrained('ar_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('GHS');
            $table->string('status', 20)->default('created')->index();
            $table->string('paystack_reference', 100)->nullable()->unique();
            $table->string('paystack_access_code', 100)->nullable();
            $table->string('authorization_url', 500)->nullable();
            $table->string('callback_url', 500)->nullable();
            $table->foreignId('ar_receipt_id')->nullable()->constrained('ar_receipts')->nullOnDelete();
            $table->string('narration', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('last_paystack_response')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent log of Paystack webhook deliveries.
 *
 * The UNIQUE on paystack_event_id (the data.id from the webhook payload)
 * is THE idempotency guard — a replayed delivery from Paystack collides
 * on INSERT and the controller short-circuits.
 *
 * The processor (PaystackWebhookProcessor) flips processed_at on success
 * and links payment_intent_id + ar_receipt_id when it produces a receipt.
 * processing_error captures async failure for the Payment Intents UI.
 *
 * No SoftDeletes — webhook events are immutable audit records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('paystack_event_id', 100)->unique();
            $table->string('event_type', 100)->index();
            $table->string('paystack_reference', 100)->nullable()->index();
            $table->json('payload');
            $table->string('signature', 255);
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->nullOnDelete();
            $table->foreignId('ar_receipt_id')->nullable()->constrained('ar_receipts')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processing_error')->nullable();
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_webhook_events');
    }
};

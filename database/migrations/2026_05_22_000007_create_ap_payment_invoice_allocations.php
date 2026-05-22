<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M:N allocation between AP payments and invoices.
 *   - One payment can cover multiple invoices (e.g. paying off a batch).
 *   - One invoice can have multiple payments (partial payments).
 * Cascades when the parent payment is deleted; restrictOnDelete on invoice
 * to keep the audit trail intact (invoice deletion via soft delete only).
 * UNIQUE(payment, invoice) — a payment can allocate to a given invoice at
 * most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_payment_invoice_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ap_payment_id')->constrained('ap_payments')->cascadeOnDelete();
            $table->foreignId('vendor_invoice_id')->constrained('vendor_invoices')->restrictOnDelete();
            $table->decimal('allocated_amount', 18, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['ap_payment_id', 'vendor_invoice_id'], 'ap_alloc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_payment_invoice_allocations');
    }
};

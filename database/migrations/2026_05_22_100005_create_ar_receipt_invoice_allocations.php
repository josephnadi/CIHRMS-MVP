<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allocation rows linking AR receipts to AR invoices. SUM(allocated_amount)
 * on a receipt MUST equal the receipt amount (enforced by ArReceiptService).
 * UNIQUE(ar_receipt_id, ar_invoice_id) — a single receipt can't allocate
 * to the same invoice twice (split allocations belong on different rows).
 * On AR invoice restrictOnDelete because allocations must be voided first
 * via ArReceiptService::void() which reverses the JE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_receipt_invoice_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ar_receipt_id')->constrained('ar_receipts')->cascadeOnDelete();
            $table->foreignId('ar_invoice_id')->constrained('ar_invoices')->restrictOnDelete();
            $table->decimal('allocated_amount', 18, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['ar_receipt_id', 'ar_invoice_id'], 'ar_receipt_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_receipt_invoice_allocations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor invoice lines. line_total = quantity * unit_price (snapshotted in
 * the service before save). Each line maps to one expense GL account; the
 * accrual JE produced on invoice creation debits each line's gl_account_id
 * for line_total + tax_amount. Cascades when the parent invoice is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_invoice_id')->constrained('vendor_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 500);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();

            $table->unique(['vendor_invoice_id', 'line_no'], 'vendor_invoice_lines_unique_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoice_lines');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items on an AR invoice. Each line credits a specific income GL
 * (must be type=income, validated in ArInvoiceService). `line_total` is
 * persisted (qty * unit_price rounded to 2dp) so reads don't recompute,
 * and `tax_amount` snapshots the line-level tax for audit replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ar_invoice_id')->constrained('ar_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 500);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();

            $table->unique(['ar_invoice_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_invoice_lines');
    }
};

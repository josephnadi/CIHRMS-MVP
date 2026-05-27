<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fee_assignments` — the (member × fee_product × period) join. Tracks
 * which members owe which fees for which billing period, independent of
 * whether the AR invoice has been generated yet.
 *
 * Unique on (member, product, period) so re-running `BillingRunService`
 * for the same period is idempotent — the second run finds the existing
 * assignment and the underlying `ar_invoice_id` stays put.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_product_id')->constrained();
            $table->string('period_label', 20);        // e.g. '2026', '2026-S1', '2026-04'
            $table->date('due_date')->nullable();

            // Set after a successful billing run; null while Pending.
            $table->foreignId('ar_invoice_id')->nullable()->constrained('ar_invoices');

            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['member_id', 'fee_product_id', 'period_label'], 'fee_assignments_unique_per_period');
            $table->index(['fee_product_id', 'period_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_assignments');
    }
};

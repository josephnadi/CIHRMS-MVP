<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor invoice (AP bill) header. Lifecycle:
 *   draft → pending_approval → approved → partially_paid → paid
 *                                       → cancelled
 * On creation, VendorInvoiceService auto-posts an accrual JournalEntry:
 *   Dr Expense GL accounts (per line), Cr AP GL account (snapshot from vendor.default_ap_gl_account_id).
 * `ap_gl_account_id` is snapshotted at creation so changes to the vendor's default
 * later don't affect this invoice's posting. `amount_paid` is maintained by
 * ApPaymentService when allocations are recorded. UNIQUE(vendor_id, vendor_invoice_no)
 * prevents accepting the same vendor's invoice number twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('vendor_invoice_no', 100)->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->char('currency', 3)->default('GHS');
            $table->foreignId('ap_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('accrual_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vendor_id', 'vendor_invoice_no'], 'vendor_invoices_vendor_number_unique');
            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoices');
    }
};

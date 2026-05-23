<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR invoice header. Lifecycle:
 *   draft → pending_approval → approved → partially_paid → paid
 *                                       → cancelled
 *                                       → written_off  (new, irreversible in F3)
 *
 * On creation, ArInvoiceService auto-posts an accrual JournalEntry:
 *   Dr AR GL (snapshot from customer.default_ar_gl_account_id, fallback 1200)
 *   Cr Income GL accounts (per line)
 *
 * `ar_gl_account_id` is snapshotted at creation so later changes to the
 * customer's default don't affect this invoice's posting. `amount_received`
 * is maintained by ArReceiptService when allocations are recorded.
 *
 * `write_off_journal_entry_id` is set by ArInvoiceService::writeOff() which
 * posts: Dr Bad Debt Expense (5600), Cr AR GL.
 *
 * UNIQUE(customer_id, customer_invoice_no) prevents duplicate caller-supplied
 * references. NULL collisions are allowed (NULL doesn't equal NULL in SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('customer_invoice_no', 100)->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('amount_received', 18, 2)->default(0);
            $table->char('currency', 3)->default('GHS');
            $table->foreignId('ar_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('accrual_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('write_off_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('written_off_at')->nullable();
            $table->string('written_off_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['customer_id', 'customer_invoice_no'], 'ar_invoices_customer_number_unique');
            $table->index('invoice_date');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_invoices');
    }
};

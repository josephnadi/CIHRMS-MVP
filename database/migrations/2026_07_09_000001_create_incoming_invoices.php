<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incoming purchase invoice intake. Departmental submitters scan/upload; an
 * auditor vets; the CEO approves; Finance codes + posts (promoting to a
 * VendorInvoice). Lifecycle:
 *   draft → submitted → vetted → approved → posted
 *   (submitted|vetted|approved) → returned → submitted (resubmit)
 * Separate from vendor_invoices so departments never touch the GL artifact
 * directly. vendor_invoice_id links to the promoted accounting invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('vendor_name', 200);
            $table->string('vendor_invoice_no', 100)->nullable();
            $table->date('invoice_date');
            $table->char('currency', 3)->default('GHS');
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('description')->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('vetted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vetted_at')->nullable();
            $table->text('vetting_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('vendor_invoice_id')->nullable()->constrained('vendor_invoices')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_invoices');
    }
};

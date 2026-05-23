<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor master data for accounts payable. Each vendor optionally pre-sets a
 * default expense GL (snapshotted onto invoice lines as a hint), a default AP
 * liability GL (snapshotted onto invoices at creation), and a preferred org
 * bank account for outgoing payments. SoftDeletes — vendors are archived to
 * preserve invoice history. Archive guard in VendorService refuses archive
 * if any non-cancelled invoices reference the vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('tax_id', 50)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('default_expense_gl_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->foreignId('default_ap_gl_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->foreignId('default_bank_account_id')->nullable()->constrained('org_bank_accounts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};

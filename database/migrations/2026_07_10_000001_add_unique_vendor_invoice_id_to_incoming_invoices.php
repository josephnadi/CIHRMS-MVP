<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defense-in-depth against a double-post: one incoming invoice may promote to at
 * most one VendorInvoice. (NULLs are allowed to repeat on both Postgres and
 * SQLite, so unposted rows are unaffected.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_invoices', function (Blueprint $table) {
            $table->unique('vendor_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_invoices', function (Blueprint $table) {
            $table->dropUnique(['vendor_invoice_id']);
        });
    }
};

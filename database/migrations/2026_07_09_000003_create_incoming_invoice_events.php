<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only audit trail of every state transition. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_invoice_id')->constrained('incoming_invoices')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_invoice_events');
    }
};

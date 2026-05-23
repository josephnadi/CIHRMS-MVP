<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entry lines — the actual debit/credit movements. A line touches
 * exactly one gl_account with EITHER debit_amount > 0 OR credit_amount > 0
 * (not both — enforced in the model's boot guard and JournalPostingService).
 * Cascades when parent journal_entries is deleted; restrictOnDelete on
 * gl_accounts to preserve audit trail (GL accounts are soft-deleted only).
 * No timestamps — lines are immutable once posted; no need for updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->string('narration', 500)->nullable();

            $table->unique(['journal_entry_id', 'line_no'], 'journal_lines_unique_line');
            $table->index('gl_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};

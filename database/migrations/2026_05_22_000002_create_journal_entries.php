<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entry header. Each row groups a balanced set of journal_lines.
 * `source_type` + `source_id` identifies the originating business object
 * (vendor invoice, AP payment, manual JE). `reversal_of_id` chains a
 * reversal back to the JE it cancels — original status flips to 'reversed'
 * and the new JE has status 'posted' with inverted debit/credit lines.
 * Posted via the central JournalPostingService — no other code writes to
 * this table or to gl_account_balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->date('entry_date');
            $table->string('narration', 500)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('source_type', 50)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('entry_date');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

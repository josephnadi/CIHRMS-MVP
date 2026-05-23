<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transaction_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_line_id')->constrained('bank_statement_lines')->restrictOnDelete();
            $table->string('matched_type', 50);
            $table->unsignedBigInteger('matched_id');
            $table->string('confidence', 10);
            $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('matched_at');
            $table->timestamp('unmatched_at')->nullable();
            $table->foreignId('unmatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unmatched_reason', 500)->nullable();

            $table->index(['matched_type', 'matched_id']);
            $table->index('matched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transaction_matches');
    }
};

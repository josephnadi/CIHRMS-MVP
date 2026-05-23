<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('running_balance', 18, 2)->nullable();
            $table->string('line_hash', 64);
            $table->string('matched_type', 50)->nullable();
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->string('confidence', 10)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['bank_statement_id', 'line_no']);
            $table->unique(['bank_statement_id', 'line_hash']);
            $table->index('reconciled_at');
            $table->index(['matched_type', 'matched_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};

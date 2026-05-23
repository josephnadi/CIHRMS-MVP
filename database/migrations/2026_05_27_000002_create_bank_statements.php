<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_bank_account_id')->constrained('org_bank_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->date('period_start')->nullable();
            $table->decimal('opening_balance', 18, 2);
            $table->decimal('closing_balance', 18, 2);
            $table->char('currency', 3)->default('GHS');
            $table->string('file_hash', 64)->unique();
            $table->string('file_name', 255);
            $table->string('format', 10);
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_bank_account_id', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->string('bank_name', 150);
            $table->string('branch', 150)->nullable();
            $table->string('account_name', 200);
            $table->string('account_number', 64);
            $table->string('sort_code', 20)->nullable();
            $table->string('swift', 20)->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->string('purpose', 30)->index();
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bank_name', 'account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_bank_accounts');
    }
};

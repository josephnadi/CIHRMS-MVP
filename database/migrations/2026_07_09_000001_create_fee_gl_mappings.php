<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_gl_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('fee_code')->unique();
            $table->string('label');
            $table->foreignId('income_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->foreignId('clearing_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->boolean('is_deferred')->default(false);
            $table->unsignedSmallInteger('recognition_months')->nullable();
            $table->foreignId('deferred_gl_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_gl_mappings');
    }
};

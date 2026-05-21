<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_account_balances', function (Blueprint $table) {
            $table->foreignId('gl_account_id')
                ->primary()
                ->constrained('gl_accounts')
                ->cascadeOnDelete();
            $table->decimal('balance', 18, 2)->default(0);
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_account_balances');
    }
};

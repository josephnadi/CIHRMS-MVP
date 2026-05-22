<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-side balance cache, one row per gl_account. Uses gl_account_id as the primary key
 * (no surrogate id) — the 1:1 relationship with gl_accounts is enforced at the schema level.
 * `cascadeOnDelete` ensures balance rows vanish with their account. Only `updated_at` is
 * tracked because rows are upserted (created once when the account is born, updated when
 * journal posts mutate the balance) — there is no meaningful `created_at` distinct from
 * the parent gl_account's creation.
 */
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

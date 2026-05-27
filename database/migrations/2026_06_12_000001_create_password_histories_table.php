<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L6 audit fix — password reuse prevention.
 *
 * Stores the last N (default 5) password hashes per user. On every password
 * change, the new hash is checked against this history; matches are
 * rejected. After acceptance, the new hash is appended and the oldest
 * trimmed to keep the table bounded.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password_hash');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};

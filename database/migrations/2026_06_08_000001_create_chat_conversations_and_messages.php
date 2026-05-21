<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal chat / messaging tables.
 *
 *   conversations          — one row per 1-on-1 thread between two users
 *   conversation_user      — pivot: user ↔ conversation, with per-user state
 *                            (last_read_at, archived_at, muted_at)
 *   chat_messages          — the messages themselves
 *
 * One-on-one only for now (no group chats), but the schema is group-ready:
 *   conversations.is_group + conversation_user pivot can hold N>2 members.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_group')->default(false);
            $table->string('title', 120)->nullable();   // null for 1:1 (we render names client-side)
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('muted_at')->nullable();
            $table->timestamps();

            // Each user appears at most once per conversation.
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'last_read_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('deleted_for_everyone_at')->nullable(); // sender retracted
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['sender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
    }
};

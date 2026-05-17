<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public API v1 supporting tables.
 *
 *  - api_token_metadata: per-token scopes, rate limits, expiry, creator audit
 *    (Sanctum's personal_access_tokens already exists; this is a 1:1 sidecar)
 *
 * Note: webhook_subscriptions and webhook_deliveries are already provisioned
 * by 2026_05_31_000002_create_webhook_subscriptions.php with a different
 * column layout (target_url / signing_secret / event_types / …) that the
 * WebhookSubscription model and dispatcher rely on. The earlier scaffold of
 * a second webhook schema in this migration was redundant and has been
 * removed to keep migrate:fresh idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sidecar metadata for personal_access_tokens. Sanctum's own scopes
        // ('abilities' column) live in personal_access_tokens; this table
        // adds operational metadata that doesn't fit on a Sanctum token.
        Schema::create('api_token_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->unique();   // references personal_access_tokens.id
            $table->foreignId('issued_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose')->nullable();      // human-readable: "GIFMIS integration", "Auditor read-only"
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('allowed_ip_cidrs')->nullable();   // optional IP allowlist
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token_metadata');
    }
};

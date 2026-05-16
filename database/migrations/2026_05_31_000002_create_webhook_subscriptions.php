<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook subscriptions — let external systems (GIFMIS, IPPD, downstream BI)
 * subscribe to events from CIHRMS. Each subscription has its own HMAC secret
 * (rotated independently) and a per-event filter list.
 *
 * Delivery is at-least-once with exponential backoff; deliveries are logged
 * for replay during reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                  // human-friendly: "GIFMIS payroll feed"
            $table->string('target_url');
            $table->text('signing_secret');                          // encrypted
            $table->json('event_types');                              // ['payroll.run.approved', 'identity.verified', ...]
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->json('payload');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 16)->default('pending');         // pending | delivered | failed | retrying
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};

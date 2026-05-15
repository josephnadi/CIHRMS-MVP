<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 12 — per-user messaging preferences.
 *
 * `notification_channels` is a JSON map keyed by channel slug
 * ({email,in_app,whatsapp,slack,teams}) → bool.
 *
 * `whatsapp_consent_at` / `whatsapp_phone` exist separately because Meta requires
 * explicit opt-in before an account can be the destination of template messages.
 *
 * `slack_user_id` is the Slack workspace user id ("U01ABC…") — null when the
 * user hasn't been mapped yet; the driver falls back to the default HR channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_channels')->nullable()->after('permissions');
            $table->string('whatsapp_phone', 20)->nullable()->after('notification_channels');
            $table->timestamp('whatsapp_consent_at')->nullable()->after('whatsapp_phone');
            $table->string('slack_user_id', 30)->nullable()->after('whatsapp_consent_at');

            $table->index('whatsapp_phone');
            $table->index('slack_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_phone']);
            $table->dropIndex(['slack_user_id']);
            $table->dropColumn(['notification_channels', 'whatsapp_phone', 'whatsapp_consent_at', 'slack_user_id']);
        });
    }
};

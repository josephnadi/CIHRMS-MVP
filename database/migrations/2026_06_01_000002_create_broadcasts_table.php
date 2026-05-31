<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $t) {
            $t->id();
            $t->string('title', 150);
            $t->string('audience_type', 64);
            $t->json('audience_params');
            $t->json('channels');
            $t->foreignId('template_id')->nullable()
                ->constrained('broadcast_templates')->nullOnDelete();
            $t->text('sms_body')->nullable();
            $t->string('mail_subject', 150)->nullable();
            $t->text('mail_body')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->boolean('throttle_overridden')->default(false);
            $t->string('throttle_override_reason', 255)->nullable();
            $t->string('status', 32)->default('queued');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('recipient_count')->default(0);
            $t->unsignedInteger('sms_sent_count')->default(0);
            $t->unsignedInteger('sms_failed_count')->default(0);
            $t->unsignedInteger('sms_throttled_count')->default(0);
            $t->unsignedInteger('mail_sent_count')->default(0);
            $t->unsignedInteger('mail_failed_count')->default(0);
            $t->softDeletes();
            $t->timestamps();

            $t->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('broadcasts'); }
};

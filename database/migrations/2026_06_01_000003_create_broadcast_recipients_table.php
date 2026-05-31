<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('broadcast_id')->constrained('broadcasts')->cascadeOnDelete();
            $t->string('recipient_type', 64);
            $t->unsignedBigInteger('recipient_id');
            $t->foreignId('sms_message_id')->nullable()
                ->constrained('sms_messages')->nullOnDelete();
            $t->string('sms_status', 16)->nullable();
            $t->string('mail_status', 16)->nullable();
            $t->text('mail_failure_reason')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['broadcast_id', 'recipient_type', 'recipient_id'], 'broadcast_recipients_unique');
            $t->index(['recipient_type', 'recipient_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('broadcast_recipients'); }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('direction');                      // outbound, inbound
            $table->string('event_type');                     // employee.created, leave.approved, webhook.message_received
            $table->string('external_id')->nullable();
            $table->json('payload');
            $table->json('response')->nullable();
            $table->string('status');                         // queued, sent, failed, received
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'status']);
            $table->index(['event_type', 'status']);
            $table->index(['direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};

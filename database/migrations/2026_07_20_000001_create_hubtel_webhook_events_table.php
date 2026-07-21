<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hubtel_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('hubtel_event_id')->unique();
            $table->string('client_reference')->nullable()->index();
            $table->string('status_text')->nullable();
            $table->json('payload');
            $table->string('signature')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubtel_webhook_events');
    }
};

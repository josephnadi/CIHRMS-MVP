<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('feed')->unique();          // 'members' | 'collections'
            $table->string('watermark')->nullable();   // ISO8601 of last processed row
            $table->unsignedBigInteger('last_cursor')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state');
    }
};

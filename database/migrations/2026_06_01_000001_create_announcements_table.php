<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->default('notice'); // AnnouncementType
            $table->string('severity', 12)->default('info'); // AnnouncementSeverity
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('audience_role', 40)->nullable(); // null = everyone
            $table->boolean('pinned')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index('type');
            $table->index('audience_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};

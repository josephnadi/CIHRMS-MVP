<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 200)->unique();
            $table->string('category', 16);
            $table->text('summary')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('category');
            $table->index('is_active');
        });

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->longText('body');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('changelog')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'version_number'], 'policy_versions_unique');
        });

        Schema::table('policies', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('policy_versions')
                ->nullOnDelete();
        });

        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('signed_full_name', 120);
            $table->timestamps();
            $table->unique(['policy_version_id', 'user_id'], 'policy_ack_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::table('policies', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policies');
    }
};

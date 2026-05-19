<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('ref_no')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->string('status')->default('draft')->index();
            $t->string('confidentiality')->default('internal');
            $t->boolean('parallel_routing')->default(false);
            $t->json('tags')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignId('version_id')->constrained('document_versions')->cascadeOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->foreignId('from_user_id')->constrained('users');
            $t->foreignId('to_user_id')->constrained('users');
            $t->string('action_required')->default('sign');
            $t->string('status')->default('pending');
            $t->timestamp('due_at')->nullable();
            $t->timestamp('acted_at')->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
            $t->index(['to_user_id', 'status']);
            $t->index(['document_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routes');
    }
};

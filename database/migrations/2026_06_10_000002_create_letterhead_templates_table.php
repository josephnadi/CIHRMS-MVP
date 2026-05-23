<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('letterhead_templates', function (Blueprint $t) {
            $t->id();
            $t->string('owner_scope', 20);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name');
            $t->string('storage_path');
            $t->string('mime', 64);
            $t->smallInteger('header_height_mm')->default(36);
            $t->boolean('is_default')->default(false);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['owner_scope', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letterhead_templates');
    }
};

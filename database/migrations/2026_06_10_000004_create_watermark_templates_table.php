<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('watermark_templates', function (Blueprint $t) {
            $t->id();
            $t->string('owner_scope', 20);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name');
            $t->string('type', 10);                  // text | image
            $t->string('text')->nullable();
            $t->string('color', 9)->nullable();
            $t->string('storage_path')->nullable();
            $t->string('mime', 64)->nullable();
            $t->decimal('opacity', 3, 2)->default(0.18);
            $t->smallInteger('angle_deg')->default(-30);
            $t->smallInteger('font_size_hint')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['owner_scope', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_templates');
    }
};

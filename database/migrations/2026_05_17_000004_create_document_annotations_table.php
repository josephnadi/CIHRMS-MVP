<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_annotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignId('version_id')->constrained('document_versions')->cascadeOnDelete();
            $t->foreignId('route_id')->nullable()->constrained('document_routes')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('type');
            $t->unsignedSmallInteger('page')->default(1);
            $t->decimal('x_pct', 7, 4);
            $t->decimal('y_pct', 7, 4);
            $t->decimal('w_pct', 7, 4)->default(10);
            $t->decimal('h_pct', 7, 4)->default(5);
            $t->smallInteger('rotation')->default(0);
            $t->json('data');
            $t->timestamps();
            $t->index(['document_id', 'version_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_annotations');
    }
};

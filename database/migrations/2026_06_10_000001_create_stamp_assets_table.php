<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stamp_assets', function (Blueprint $t) {
            $t->id();
            $t->string('owner_scope', 20);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name');
            $t->string('storage_path');
            $t->string('mime', 64);
            $t->decimal('default_w_pct', 5, 2)->default(18);
            $t->decimal('default_h_pct', 5, 2)->default(6);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['owner_scope', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_assets');
    }
};

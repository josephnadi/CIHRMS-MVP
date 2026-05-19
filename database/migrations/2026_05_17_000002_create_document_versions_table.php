<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->unsignedSmallInteger('version_no');
            $t->string('original_name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('storage_path');
            $t->string('sha256', 64)->index();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamp('uploaded_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'version_no']);
        });

        Schema::table('documents', function (Blueprint $t) {
            $t->foreign('current_version_id')
              ->references('id')->on('document_versions')
              ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('document_versions');
    }
};

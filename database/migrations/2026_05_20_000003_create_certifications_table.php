<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('issuer')->nullable();
            $table->string('credential_id')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('document_path')->nullable();
            $table->string('verification_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->default('technical');           // CourseCategory
            $table->string('format')->default('self_paced');            // CourseFormat
            $table->string('provider')->nullable();                     // Coursera, internal, instructor name
            $table->string('cover_image')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->json('skill_tags')->nullable();                     // ["python","leadership"] — granted on completion
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};

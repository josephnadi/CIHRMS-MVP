<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('review_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();    // subject
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');                                                // ReviewType
            $table->decimal('overall_rating', 3, 2)->nullable();                   // 1.00 .. 5.00
            $table->decimal('performance_rating', 3, 2)->nullable();               // 9-box X-axis (current performance)
            $table->decimal('potential_rating', 3, 2)->nullable();                 // 9-box Y-axis (future potential)
            $table->text('strengths')->nullable();
            $table->text('opportunities')->nullable();
            $table->text('comments')->nullable();
            $table->string('status')->default('draft');                            // ReviewStatus
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cycle_id', 'employee_id', 'reviewer_id', 'type']);
            $table->index(['employee_id', 'status']);
            $table->index('reviewer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};

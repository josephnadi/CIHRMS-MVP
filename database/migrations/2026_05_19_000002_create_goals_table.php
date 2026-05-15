<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_goal_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('review_cycles')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cadence')->default('quarterly');         // GoalCadence
            $table->decimal('target_value', 14, 2)->nullable();
            $table->decimal('current_value', 14, 2)->default(0);
            $table->string('unit', 20)->nullable();                  // %, $, units, etc.
            $table->unsignedTinyInteger('weight')->default(100);     // 0-100 weighting toward overall
            $table->string('status')->default('draft');              // GoalStatus
            $table->date('starts_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index('cycle_id');
            $table->index('parent_goal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};

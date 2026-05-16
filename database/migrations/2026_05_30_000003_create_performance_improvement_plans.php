<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance Improvement Plans — the formal process opened for any employee
 * whose end-cycle rating falls below the org's minimum acceptable threshold.
 * Required step before any non-disciplinary termination per Ghana Labour
 * Act 2003 (Act 651) §17 (notice) and §63 (unfair termination protections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_improvement_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by_review_id')->nullable()->constrained('reviews')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('mentor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 24)->default('open');

            $table->date('opened_on');
            $table->date('target_end_date');
            $table->date('actual_end_date')->nullable();
            $table->unsignedSmallInteger('extensions_used')->default(0);
            $table->unsignedSmallInteger('max_extensions')->default(1);

            $table->json('target_metrics');     // [{ metric, target, actual?, met? }]
            $table->json('checkins')->nullable(); // [{ date, note, met_target_bool }]
            $table->text('outcome_summary')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_improvement_plans');
    }
};

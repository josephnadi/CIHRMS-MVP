<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PSC-style performance contracts — a signed agreement at the START of a
 * cycle laying out the KPIs the employee will be measured against. Public
 * Service Commission of Ghana made performance contracts mandatory for
 * Public Service Organisation heads under the Performance Management Policy
 * Framework (Cabinet, April 2015). We extend that pattern to all staff.
 *
 * The `kpis` JSON column holds an array of { id, name, weight, target,
 * actual, score } objects. End-of-cycle evaluation walks this array,
 * computes a weighted overall achievement, and transitions the contract
 * to `achieved` or `missed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('review_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 24)->default('draft');

            // KPI catalogue
            $table->json('kpis')->nullable();              // [{ id, name, weight, target, actual?, score? }]
            $table->json('balanced_scorecard')->nullable(); // tags KPIs to Financial/Customer/Process/Learning
            $table->decimal('weighted_achievement', 5, 2)->nullable(); // computed end-of-cycle (0–100)

            // Signing workflow
            $table->foreignId('drafted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('employee_signed_at')->nullable();
            $table->timestamp('supervisor_signed_at')->nullable();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalised_at')->nullable();

            $table->text('mid_year_note')->nullable();
            $table->text('end_year_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cycle_id', 'employee_id'], 'perf_contract_unique');
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_contracts');
    }
};

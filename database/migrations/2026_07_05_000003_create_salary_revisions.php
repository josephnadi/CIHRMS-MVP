<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary revision events (CIHRM's "Revised Basic Salary (10%)"). Applying a
 * revision writes new effective-dated grade_steps.base_salary rows (old ×
 * factor) and closes the prior ones — this table is the audit/reproducibility
 * record of each revision. Percentage is institute-wide; per-grade overrides
 * (grade_id → percentage) are kept in grade_overrides for reproducibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('scope', 16)->default('institute'); // institute | grade
            $table->decimal('percentage', 6, 3);               // e.g. 10.000 = +10%
            $table->date('effective_from');
            $table->json('grade_overrides')->nullable();       // { grade_id: percentage }
            $table->unsignedInteger('affected_count')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_revisions');
    }
};

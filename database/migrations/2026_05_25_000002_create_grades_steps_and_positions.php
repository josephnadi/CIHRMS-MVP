<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Establishment control — public-sector grades, step-points, positions,
 * position assignments, and per-department establishment ceilings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // e.g. GS-12, SS-3
            $table->string('name');
            $table->unsignedSmallInteger('level')->default(0); // ordering for org charts
            $table->unsignedTinyInteger('min_step')->default(1);
            $table->unsignedTinyInteger('max_step')->default(10);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('grade_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->decimal('base_salary', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['grade_id', 'step', 'effective_from'], 'grade_steps_unique');
            $table->index(['grade_id', 'step'], 'grade_steps_lookup');
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('title');
            $table->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reports_to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('cost_center', 32)->nullable();
            $table->string('funding_source', 16)->default('gog');
            $table->string('status', 16)->default('vacant');
            $table->unsignedSmallInteger('headcount_ceiling')->default(1);
            $table->boolean('is_supervisory')->default(false);
            $table->text('job_description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'status']);
            $table->index('grade_id');
            $table->index('reports_to_position_id');
        });

        Schema::create('position_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_acting')->default(false);
            $table->unsignedTinyInteger('step_at_start')->default(1);
            $table->string('reason')->nullable(); // assignment | transfer | promotion | acting | demotion
            $table->timestamps();

            $table->index(['position_id', 'employee_id']);
            $table->index(['employee_id', 'start_date']);
        });

        Schema::create('establishment_ceilings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('approved_headcount');
            $table->text('approval_reference')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'grade_id', 'fiscal_year'], 'establishment_unique');
        });

        // Wire employees to current position + grade + step.
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('current_position_id')->nullable()->after('manager_id')->constrained('positions')->nullOnDelete();
            $table->foreignId('current_grade_id')->nullable()->after('current_position_id')->constrained('grades')->nullOnDelete();
            $table->unsignedTinyInteger('current_step')->nullable()->after('current_grade_id');
            $table->date('step_anniversary_date')->nullable()->after('current_step');
            $table->string('ssnit_number', 32)->nullable()->after('national_id');
            $table->string('tin_number', 32)->nullable()->after('ssnit_number');
            $table->foreignId('tier2_trustee_id')->nullable()->after('tin_number'); // FK added when trustees table exists
            $table->index('current_position_id');
            $table->index('current_grade_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['current_position_id']);
            $table->dropForeign(['current_grade_id']);
            $table->dropColumn([
                'current_position_id', 'current_grade_id', 'current_step',
                'step_anniversary_date', 'ssnit_number', 'tin_number', 'tier2_trustee_id',
            ]);
        });

        Schema::dropIfExists('establishment_ceilings');
        Schema::dropIfExists('position_assignments');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('grade_steps');
        Schema::dropIfExists('grades');
    }
};

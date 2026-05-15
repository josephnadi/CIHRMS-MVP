<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->string('type', 24);
            $table->string('provider', 120)->nullable();
            $table->text('description')->nullable();
            $table->decimal('monthly_cost', 10, 2);
            $table->decimal('employee_contribution_percentage', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedSmallInteger('max_dependants')->default(0);
            $table->json('cover_details')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('type');
            $table->index('is_active');
        });

        Schema::create('benefit_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('benefit_plans')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('enrolled_at');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 12)->default('active');
            $table->decimal('monthly_premium', 10, 2);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['plan_id', 'employee_id', 'effective_from'], 'benefit_enrolments_unique');
            $table->index('employee_id');
            $table->index('status');
        });

        Schema::create('dependants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 120);
            $table->string('relationship', 12);
            $table->date('date_of_birth');
            $table->string('national_id', 32)->nullable();
            $table->string('gender', 16)->nullable();
            $table->boolean('is_covered')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->index('employee_id');
        });

        Schema::create('benefit_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrolment_id')->constrained('benefit_enrolments')->cascadeOnDelete();
            $table->string('claim_reference', 20)->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('GHS');
            $table->date('claim_date');
            $table->text('description');
            $table->string('status', 12)->default('submitted');
            $table->timestamp('submitted_at');
            $table->timestamp('decision_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->index('enrolment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_claims');
        Schema::dropIfExists('dependants');
        Schema::dropIfExists('benefit_enrolments');
        Schema::dropIfExists('benefit_plans');
    }
};

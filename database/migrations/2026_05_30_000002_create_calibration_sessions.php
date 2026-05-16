<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calibration sessions — the formal meeting where line managers compare and
 * adjust ratings across their cohort to prevent rating inflation/deflation
 * and ensure a defensible distribution before ratings drive pay/promotion.
 *
 * The session captures the *original* manager rating and the *adjusted*
 * rating side-by-side with the calibrator's reason. When the session is
 * `applied`, adjusted ratings flow back to the underlying reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('review_cycles')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->foreignId('facilitated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('target_distribution')->nullable();   // e.g. {"5":0.10, "4":0.25, "3":0.40, "2":0.20, "1":0.05}
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cycle_id', 'status']);
        });

        Schema::create('calibration_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('calibration_sessions')->cascadeOnDelete();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->decimal('original_rating', 3, 2);
            $table->decimal('adjusted_rating', 3, 2);
            $table->text('reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'review_id'], 'calibration_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_adjustments');
        Schema::dropIfExists('calibration_sessions');
    }
};

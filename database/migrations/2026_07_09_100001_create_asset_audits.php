<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical asset-audit run. An auditor opens a run scoped to all / a category /
 * a location; the expected asset set is snapshotted into asset_audit_lines.
 * Lifecycle: in_progress → completed (or → cancelled). Tallies (counted_lines,
 * discrepancy_lines) are recomputed from the lines as counting proceeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audits', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('status', 20)->default('in_progress')->index();
            $table->string('scope_type', 20);           // all | category | location
            $table->string('scope_value', 120)->nullable();
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('counted_lines')->default(0);
            $table->unsignedInteger('discrepancy_lines')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audits');
    }
};

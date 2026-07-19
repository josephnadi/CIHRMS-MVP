<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_audit_id')->constrained('asset_audits')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('expected_status', 16);
            $table->string('expected_location', 120)->nullable();
            $table->foreignId('expected_holder_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('result', 20)->default('pending');
            $table->string('observed_location', 120)->nullable();
            $table->text('observed_note')->nullable();
            $table->boolean('is_discrepancy')->default(false);
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->string('resolution_action', 20)->default('none');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolved_note')->nullable();
            $table->timestamps();

            $table->unique(['asset_audit_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audit_lines');
    }
};

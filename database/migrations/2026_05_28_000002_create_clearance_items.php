<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-case clearance checklist. Rows are seeded from a default template at
 * case creation but can be added to or removed by HR. Each item has its own
 * owner (responsible_department + responsible_user) so escalations are clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clearance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_case_id')->constrained()->cascadeOnDelete();
            $table->string('area', 32);                       // it_assets|finance|hr_records|...
            $table->string('label');                          // human-readable description
            $table->string('status', 16)->default('pending'); // pending|cleared|waived
            $table->foreignId('responsible_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_required')->default(true);    // false = optional, doesn't block completion
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence_paths')->nullable();        // attached files/receipts
            $table->timestamps();

            $table->index(['offboarding_case_id', 'status']);
            $table->index(['area', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_items');
    }
};

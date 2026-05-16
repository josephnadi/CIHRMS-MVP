<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data-subject requests under Ghana Data Protection Act 2012 (Act 843).
 *
 *  - 30-day SLA enforced via `target_completion_date`.
 *  - Each request can produce an export ZIP at `export_path` (Access /
 *    Portability / Information types).
 *  - For Erasure requests, the `tombstone_log` JSON captures which fields
 *    were redacted and which were held back under statutory exceptions
 *    (payroll/tax retention, audit-chain integrity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();                 // DSR-2026-00001
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_type', 32);                        // access | rectification | …
            $table->string('status', 32)->default('submitted');

            $table->text('subject_statement');                         // what the subject is asking for
            $table->text('rectification_details')->nullable();         // for rectification requests
            $table->text('objection_purpose')->nullable();             // for objection — which processing

            $table->timestamp('submitted_at');
            $table->date('target_completion_date');                    // submitted_at + 30 days
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();   // DPO
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_summary')->nullable();              // visible to the subject
            $table->text('rejection_basis')->nullable();               // statutory citation if rejected

            $table->string('export_path')->nullable();                 // storage path of generated ZIP
            $table->string('export_sha256', 64)->nullable();           // tamper-evidence
            $table->timestamp('export_generated_at')->nullable();

            $table->json('tombstone_log')->nullable();                 // erasure receipts: which fields, when, why-held
            $table->json('audit_trail')->nullable();                   // chronological events for THIS request

            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_user_id', 'status']);
            $table->index(['status', 'target_completion_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};

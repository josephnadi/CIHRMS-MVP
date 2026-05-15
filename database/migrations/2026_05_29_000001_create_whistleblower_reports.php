<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous whistleblower disclosures per the Whistleblower Act 2006 (Act 720).
 *
 * Anonymity model:
 *  - The submitter receives a one-time tracking code (12-char base32) which
 *    is stored only as a SHA-256 hash in `tracking_token_hash`. The plaintext
 *    code lives ONLY with the submitter — no database lookup can recover it.
 *  - All free-text content (`description`, `desired_outcome`, `submitter_contact`,
 *    `incident_location`) is encrypted at rest via the Laravel `encrypted` cast,
 *    keyed by APP_KEY.
 *  - When `is_anonymous = true`, `submitter_user_id` MUST be null. The
 *    application layer enforces this; the schema permits both null for true
 *    anonymity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblower_reports', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 32)->unique();              // WB-2026-00001
            $table->char('tracking_token_hash', 64)->unique();        // sha256(plaintext code)

            // Classification (set during triage; nullable at intake)
            $table->string('category', 32);                           // matches WhistleblowerCategory
            $table->string('severity', 16)->nullable();
            $table->string('status', 32)->default('submitted');

            // Public-safe summary (NOT encrypted — used in listing)
            $table->string('subject_summary');                        // 1-line title shown in dashboard
            $table->date('incident_date')->nullable();

            // Encrypted PII / details
            $table->text('description');                              // encrypted cast on model
            $table->text('desired_outcome')->nullable();              // encrypted
            $table->text('incident_location')->nullable();            // encrypted
            $table->text('submitter_contact')->nullable();            // encrypted; only present if opted-in
            $table->boolean('is_anonymous')->default(true);
            $table->foreignId('submitter_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Workflow
            $table->foreignId('assigned_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('triaged_at')->nullable();
            $table->foreignId('triaged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_summary')->nullable();              // encrypted

            $table->timestamp('received_at');
            $table->string('intake_source', 32)->default('web_form'); // web_form, hotline, postal, email
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'severity']);
            $table->index('assigned_investigator_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblower_reports');
    }
};

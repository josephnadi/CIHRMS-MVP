<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables for whistleblower cases:
 *  - subjects:      people / entities named in the report (encrypted)
 *  - evidence:      attached files (path stored; file content encrypted at rest)
 *  - actions:       investigator audit trail
 *  - messages:      secure two-way thread keyed by case (submitter posts via tracking code)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblower_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('whistleblower_reports')->cascadeOnDelete();
            $table->text('subject_label');                              // encrypted
            $table->foreignId('linked_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('role_context')->nullable();                   // encrypted; "their role in the incident"
            $table->timestamps();

            $table->index('report_id');
        });

        Schema::create('whistleblower_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('whistleblower_reports')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('storage_path');                             // encrypted disk path
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->text('caption')->nullable();                        // encrypted
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete(); // null = anonymous submitter
            $table->timestamps();

            $table->index('report_id');
        });

        Schema::create('whistleblower_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('whistleblower_reports')->cascadeOnDelete();
            $table->foreignId('investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_type', 32);                          // matches InvestigationActionType
            $table->text('notes')->nullable();                          // encrypted
            $table->json('meta')->nullable();                           // e.g. {referred_to: 'CHRAJ', reference_no: '...'}
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['report_id', 'occurred_at']);
        });

        Schema::create('whistleblower_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('whistleblower_reports')->cascadeOnDelete();
            $table->string('direction', 16);                            // inbound (submitter→investigator) | outbound (investigator→submitter)
            $table->text('body');                                       // encrypted
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); // null when posted via tracking code
            $table->timestamp('posted_at');
            $table->timestamp('read_at')->nullable();                   // when the OTHER party read it
            $table->timestamps();

            $table->index(['report_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblower_messages');
        Schema::dropIfExists('whistleblower_actions');
        Schema::dropIfExists('whistleblower_evidence');
        Schema::dropIfExists('whistleblower_subjects');
    }
};

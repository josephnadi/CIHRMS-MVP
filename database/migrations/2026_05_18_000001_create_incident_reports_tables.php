<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incident_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $t->enum('category', ['grievance', 'improvement', 'safety', 'other']);
            $t->string('title', 180);
            $t->text('body');
            $t->enum('status', ['open', 'in_review', 'closed'])->default('open');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('resolution_note')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['employee_id', 'status']);
            $t->index(['status', 'created_at']);
        });

        Schema::create('incident_report_assignees', function (Blueprint $t) {
            $t->foreignId('incident_report_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('assigned_at')->useCurrent();
            $t->foreignId('assigned_by_id')->constrained('users');
            $t->timestamp('removed_at')->nullable();
            $t->primary(['incident_report_id', 'user_id']);
        });

        Schema::create('incident_report_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_report_id')->constrained()->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users');
            $t->text('body');
            $t->timestamps();
            $t->index(['incident_report_id', 'created_at']);
        });

        Schema::create('incident_report_attachments', function (Blueprint $t) {
            $t->id();
            $t->string('attachable_type');
            $t->unsignedBigInteger('attachable_id');
            $t->string('file_path');
            $t->string('original_name');
            $t->string('mime_type', 120);
            $t->unsignedInteger('size_bytes');
            $t->foreignId('uploaded_by_id')->constrained('users');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_report_attachments');
        Schema::dropIfExists('incident_report_messages');
        Schema::dropIfExists('incident_report_assignees');
        Schema::dropIfExists('incident_reports');
    }
};

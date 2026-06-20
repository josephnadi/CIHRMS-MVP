<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two previously-dropped fields on leave decisions/applications:
     *  - decision_comment / decided_at: the approver's note + timestamp captured
     *    at approve/reject (the UI sent `comment` but it was never persisted).
     *  - attachment_path: supporting document uploaded on the apply form (the
     *    file was uploaded but discarded). Stored on the private 'local' disk.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->text('decision_comment')->nullable()->after('status');
            $table->timestamp('decided_at')->nullable()->after('decision_comment');
            $table->string('attachment_path')->nullable()->after('decided_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['decision_comment', 'decided_at', 'attachment_path']);
        });
    }
};

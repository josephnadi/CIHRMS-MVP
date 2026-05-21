<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds public-submission columns to `data_subject_requests` so subjects with
 * no CIHRMS account can exercise their Act 843 §17 rights (access /
 * rectification / erasure / portability).
 *
 *   - subject_email + subject_full_name : identifies the subject
 *   - verification_token                : 80-char hex emailed to the subject
 *   - verified_at                       : set when they click the magic link
 *
 * Until verified_at is set, the request stays in `pending_verification`
 * status and is invisible to the DPO queue. After verification it joins the
 * normal Act-843 30-day SLA clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table) {
            $table->string('subject_email')->nullable()->after('subject_user_id');
            $table->string('subject_full_name')->nullable()->after('subject_email');
            $table->string('verification_token', 80)->nullable()->after('subject_full_name');
            $table->timestamp('verified_at')->nullable()->after('verification_token');

            $table->index('subject_email');
            $table->index('verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table) {
            $table->dropIndex(['subject_email']);
            $table->dropIndex(['verification_token']);
            $table->dropColumn(['subject_email', 'subject_full_name', 'verification_token', 'verified_at']);
        });
    }
};

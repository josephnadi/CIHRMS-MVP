<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the read-only member-mirror keys: `external_user_id` is the
 * website's (`cihrm_website`) `users.id` — the website is the source of
 * truth, mvp only mirrors for billing/GL drill-down. `student_no` mirrors
 * the website's student number where the member is a student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedBigInteger('external_user_id')->nullable()->unique()->after('id');
            $table->string('student_no', 30)->nullable()->after('member_no');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['external_user_id', 'student_no']);
        });
    }
};

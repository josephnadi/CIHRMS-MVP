<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred-income (Subscription in Advance, Note 10) recognition.
 *
 * When a deferred subscription is collected it is credited to the deferred
 * liability (2400). A schedule + one entry per month straight-lines the release
 * to income; the monthly recognition run posts DR 2400 / CR income for each due
 * entry. The period starts on the payment date and runs `months` months.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_recognition_schedules', function (Blueprint $table) {
            $table->id();
            // Polymorphic-ish source (the deferred posting this schedule releases).
            $table->string('source_type', 40);          // e.g. 'external_collection'
            $table->unsignedBigInteger('source_id');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('income_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->foreignId('deferred_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->decimal('total_amount', 14, 2);
            $table->unsignedSmallInteger('months');
            $table->date('start_date');                 // = payment date
            $table->decimal('recognized_total', 14, 2)->default(0);
            $table->string('status', 16)->default('active'); // active | completed | cancelled
            $table->timestamps();

            $table->unique(['source_type', 'source_id']); // one schedule per deferred posting
            $table->index('status');
        });

        Schema::create('revenue_recognition_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('revenue_recognition_schedules')->cascadeOnDelete();
            $table->string('period_month', 7);          // 'YYYY-MM'
            $table->decimal('amount', 14, 2);
            $table->string('status', 16)->default('pending'); // pending | recognized | cancelled
            $table->timestamp('recognized_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'period_month']);
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_recognition_entries');
        Schema::dropIfExists('revenue_recognition_schedules');
    }
};

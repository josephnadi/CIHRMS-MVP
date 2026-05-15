<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw clock-in/clock-out events. One row per punch. The derived daily/monthly
 * status lives in `attendance_summaries` for fast period queries.
 *
 * `event_at` is the device-side timestamp (UTC). `recorded_at` is when we
 * received it — they can diverge if a device buffered offline and replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->string('source', 16);                       // biometric|gps_mobile|web_kiosk|manual|webhook
            $table->string('direction', 4);                     // in|out
            $table->timestamp('event_at');                       // when the punch happened (device-side)
            $table->timestamp('recorded_at')->useCurrent();      // when we received it
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();
            $table->string('biometric_score', 16)->nullable();   // confidence from the device
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // for manual entries
            $table->text('reason')->nullable();                  // required for manual entries
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'event_at']);
            $table->index(['device_id', 'event_at']);
        });

        Schema::create('attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');
            $table->string('status', 16);                        // present|late|half_day|absent|on_leave|holiday|weekend
            $table->time('first_in')->nullable();
            $table->time('last_out')->nullable();
            $table->decimal('hours_worked', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->boolean('is_weekend')->default(false);
            $table->boolean('is_holiday')->default(false);
            $table->string('source', 16)->nullable();            // dominant source for the day
            $table->timestamps();

            $table->unique(['employee_id', 'summary_date'], 'attendance_summary_unique');
            $table->index('summary_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_summaries');
        Schema::dropIfExists('attendance_records');
    }
};

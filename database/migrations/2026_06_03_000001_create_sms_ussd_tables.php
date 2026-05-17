<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 / WS18 — USSD & SMS reach for low-end devices.
 *
 * Tables:
 *   - sms_messages       outbound message log (provider, status, delivery receipts)
 *   - inbound_sms        raw inbound texts from the short code
 *   - ussd_sessions      USSD state machine (Hubtel-shaped; provider-agnostic)
 *   - staff_phone_pins   per-employee PIN to authenticate self-service USSD
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('to_phone', 32);
            $table->string('from_sender', 32)->nullable();        // alphanumeric sender ID
            $table->text('body');
            $table->string('provider', 32);                        // hubtel | mnotify | twilio
            $table->string('status', 16)->default('queued');
            $table->string('provider_message_id', 64)->nullable();
            $table->unsignedSmallInteger('segments')->default(1);  // 160-char chunks
            $table->decimal('cost', 8, 4)->default(0);
            $table->text('failure_reason')->nullable();
            $table->string('context_type', 64)->nullable();        // 'payroll' | 'leave' | 'whistleblower' | ...
            $table->unsignedBigInteger('context_id')->nullable();  // polymorphic ref
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['to_phone', 'status']);
            $table->index(['context_type', 'context_id']);
        });

        Schema::create('inbound_sms', function (Blueprint $table) {
            $table->id();
            $table->string('from_phone', 32);
            $table->string('to_shortcode', 16);
            $table->text('body');
            $table->string('provider', 32);
            $table->string('provider_message_id', 64)->nullable();
            $table->string('parsed_intent', 64)->nullable();        // PAYSLIP | LEAVE | CLOCK_IN | CLOCK_OUT | TRACK
            $table->json('parsed_args')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('reply_sent')->nullable();
            $table->timestamps();

            $table->index(['from_phone', 'received_at']);
        });

        Schema::create('ussd_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->unique();             // provider's session id
            $table->string('phone', 32);
            $table->string('shortcode', 16);
            $table->string('state', 32)->default('welcome');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->json('context')->nullable();                    // breadcrumb of inputs + scratch values
            $table->text('last_input')->nullable();
            $table->text('last_response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'state']);
        });

        Schema::create('staff_phone_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('pin_hash');                              // bcrypt hashed
            $table->timestamp('pin_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_phone_pins');
        Schema::dropIfExists('ussd_sessions');
        Schema::dropIfExists('inbound_sms');
        Schema::dropIfExists('sms_messages');
    }
};

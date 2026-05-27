<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `member_phone_pins` — short numeric PIN that authorises a Member to
 * use the USSD member-fees flow (M3). Mirrors `staff_phone_pins` for
 * Employees. Stored as a bcrypt hash so the raw PIN is never recoverable
 * even from a DB dump.
 *
 * Lockout policy matches staff: 5 wrong attempts → 15-minute lock.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_phone_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 30)->index();
            $table->string('pin_hash');
            $table->timestamp('pin_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_phone_pins');
    }
};

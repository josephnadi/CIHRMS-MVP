<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered biometric / clock-in hardware. Each device has its own HMAC
 * shared secret so a compromised device can be revoked without re-keying
 * the rest of the estate.
 *
 * Supports ZKTeco, Hikvision, Suprema and any device that can POST a JSON
 * event with an HMAC-SHA256 signature header.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();           // DEV-ACCRA-MAIN-01
            $table->string('name');
            $table->string('vendor', 32)->default('zkteco'); // zkteco | hikvision | suprema | other
            $table->string('location')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->text('shared_secret');                   // HMAC key — encrypted via cast
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('geo_radius_m')->nullable(); // for GPS-paired devices
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ghana Card / NIA identity verification audit table.
 *
 * Every successful verification stores a non-PII fingerprint so duplicate-detection
 * doesn't need to compare the raw Ghana Card number across employees in plaintext.
 * Raw card number is kept encrypted via the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32); // nia_official | third_party_kyc | manual_upload
            $table->string('ghana_card_number');                     // encrypted via model cast
            $table->string('ghana_card_hash', 64)->index();          // sha256 fingerprint for dup-detection
            $table->string('status', 16)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->string('evidence_path')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};

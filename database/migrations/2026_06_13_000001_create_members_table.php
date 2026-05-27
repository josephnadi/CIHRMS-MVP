<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `members` — CIHRM members and students (the billable parties for the
 * Billing & Fees module, M1). Each member 1:1 maps to a hidden `Customer`
 * row so the existing AR module flows unchanged. The `password` column is
 * populated in M2 when we add the member portal guard; nullable here so
 * M1 can ship without a self-service login.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_no', 30)->unique();
            $table->string('class', 20)->index();   // MemberClass enum value
            $table->string('status', 20)->default('active')->index();
            $table->string('name', 200);
            $table->string('email', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            // Hashed for lookup; raw Ghana Card never stored.
            $table->string('ghana_card_number_hash', 64)->nullable();

            // 1:1 link to the existing Customer (the AR-side identity).
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();

            $table->timestamp('chartered_at')->nullable();
            $table->timestamp('lapsed_at')->nullable();

            // Member portal auth (M2). Nullable so M1 can ship without it.
            $table->string('password')->nullable();
            $table->string('remember_token', 100)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['class', 'status']);
            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};

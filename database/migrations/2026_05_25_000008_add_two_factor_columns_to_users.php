<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication (TOTP) — privileged roles cannot reach the
 * dashboard without enrolling. Sensitive actions (payroll approve, role
 * elevation) require a fresh challenge within 5 minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');                 // encrypted
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret'); // encrypted JSON
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->boolean('two_factor_required')->default(false)->after('two_factor_confirmed_at');
            $table->timestamp('two_factor_last_used_at')->nullable()->after('two_factor_required');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_required',
                'two_factor_last_used_at',
            ]);
        });
    }
};

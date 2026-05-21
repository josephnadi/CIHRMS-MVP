<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts. Hierarchical via `parent_id` self-FK (NPO-style multi-level chart).
 * `type` constrains the account's accounting class (asset/liability/equity/income/expense)
 * and is validated at the application layer against the GlAccountType enum.
 * SoftDeletes — accounts are archived, never hard-deleted, to preserve historical posting links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('type', 20)->index();
            $table->foreignId('parent_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->char('currency', 3)->default('GHS');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
    }
};

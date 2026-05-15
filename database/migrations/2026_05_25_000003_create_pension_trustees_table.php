<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NPRA-licensed corporate trustees managing Tier-2 occupational pensions.
 * Each employee can be assigned to one trustee; the Tier-2 schedule is
 * generated per-trustee per pay-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pension_trustees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('npra_license_number', 64)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('schedule_format', 32)->default('csv'); // csv | xlsx | xml
            $table->boolean('is_active')->default(true);
            $table->json('schedule_columns')->nullable(); // trustee-specific column ordering
            $table->timestamps();
            $table->softDeletes();
        });

        // Now we can add the FK constraint that was deferred from the previous migration.
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('tier2_trustee_id')->references('id')->on('pension_trustees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['tier2_trustee_id']);
        });

        Schema::dropIfExists('pension_trustees');
    }
};
